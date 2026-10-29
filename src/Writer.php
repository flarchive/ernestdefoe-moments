<?php

namespace Ernestdefoe\Moments;

use Carbon\Carbon;
use Ernestdefoe\Moments\Media\Storage;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;

/**
 * Writing moments and replies: validated, parsed, counted, indexed, then
 * announced (notifications and the real-time nudge). Controllers hand the
 * request body here; nothing else writes an entry.
 */
class Writer
{
    public function __construct(
        protected ConnectionInterface $db,
        protected Content $content,
        protected Settings $settings,
        protected Access $access,
        protected Visibility $visibility,
        protected Follows $follows,
        protected Topics $topics,
        protected Notifier $notifier,
        protected Broadcaster $broadcaster,
        protected Storage $storage,
        protected TranslatorInterface $translator
    ) {
    }

    protected function fail(string $field, string $key, array $params = []): never
    {
        throw new ValidationException([$field => $this->translator->trans('ernestdefoe-moments.api.'.$key, $params)]);
    }

    /** @param array<string, mixed> $body */
    public function create(User $actor, array $body): Entry
    {
        $parent = null;
        $root = null;

        if ($parentId = (int) Arr::get($body, 'parentId', 0)) {
            $parent = $this->visibility->entries($actor)->find($parentId);
            if (! $parent) {
                throw new PermissionDeniedException();
            }
            $root = $parent->isRoot() ? $parent : $this->visibility->entries($actor)->find($parent->root_id);
            if (! $root || ! $this->access->canReply($actor, $root)) {
                throw new PermissionDeniedException();
            }
        } elseif (! Access::canPost($actor)) {
            throw new PermissionDeniedException();
        }

        $repostOf = null;
        if (! $parent && ($repostId = (int) Arr::get($body, 'repostOf', 0))) {
            $repostOf = $this->visibility->entries($actor)->find($repostId);
            if (! $repostOf || ! $this->access->canRepost($actor, $repostOf)) {
                throw new PermissionDeniedException();
            }
            if (Entry::query()->where('user_id', $actor->id)->where('repost_of_id', $repostOf->id)->exists()) {
                $this->fail('repostOf', 'already_reposted');
            }
        }

        $source = (string) Arr::get($body, 'content', '');
        $this->checkLength($source);

        $mediaIds = array_slice(array_values(array_unique(array_map('intval', (array) Arr::get($body, 'media', [])))), 0, 20);
        $media = $mediaIds
            ? Media::query()->whereIn('id', $mediaIds)->where('user_id', $actor->id)->whereNull('entry_id')->get()->sortBy(fn (Media $m) => array_search($m->id, $mediaIds))->values()
            : collect();

        if (count($mediaIds) !== $media->count()) {
            $this->fail('media', 'media_missing');
        }
        if ($media->count() > $this->settings->maxMedia()) {
            $this->fail('media', 'too_much_media', ['{max}' => $this->settings->maxMedia()]);
        }
        if ($media->where('kind', 'audio')->count() > 1 || ($media->where('kind', 'audio')->count() && $media->count() > 1)) {
            $this->fail('media', 'voice_alone');
        }

        if (trim($source) === '' && $media->isEmpty() && ! $repostOf) {
            $this->fail('content', 'empty');
        }

        $privacy = $root ? $root->privacy : (string) Arr::get($body, 'privacy', $this->settings->defaultPrivacy());
        if (! in_array($privacy, Entry::PRIVACIES, true) || ($privacy === Entry::FOLLOWERS && ! $this->follows->enabled())) {
            $this->fail('privacy', 'bad_privacy');
        }

        $entry = new Entry();
        $entry->user_id = $actor->id;
        $entry->audience_user_id = $root ? $root->user_id : $actor->id;
        $entry->privacy = $privacy;
        $entry->created_at = Carbon::now();

        if ($parent && $root) {
            $entry->root_id = $root->id;
            $entry->parent_id = $parent->id;
            $entry->depth = min(255, $parent->depth + 1);
            $entry->thread_id = $parent->isRoot() ? null : ($parent->thread_id ?? $parent->id);
        }
        $entry->repost_of_id = $repostOf?->id;

        $this->db->transaction(function () use ($entry, $source, $actor, $media) {
            $entry->save();
            if ($entry->parent_id !== null && $entry->thread_id === null) {
                // A top-level reply heads its own thread.
                $entry->thread_id = $entry->id;
            }
            $entry->content = $this->content->parse($source, $entry, $actor);
            $entry->search_text = $this->searchText($entry->content);
            $entry->save();

            foreach ($media->values() as $i => $m) {
                $m->entry_id = $entry->id;
                $m->position = $i;
                $m->save();
            }
        });

        $this->topics->sync($entry);
        $this->recountFor($entry);

        $entry->setRelation('user', $actor);
        // Mentions looked up once, for the alerts, the emails and the response alike.
        $this->content->prime([$entry]);
        $this->notifier->created($entry, $actor, $parent, $root, $repostOf);
        $this->broadcaster->created($entry);

        return $entry;
    }

    /** @param array<string, mixed> $body */
    public function update(User $actor, Entry $entry, array $body): Entry
    {
        $changed = false;

        if (array_key_exists('content', $body) || array_key_exists('privacy', $body)) {
            if (! $this->access->canEdit($actor, $entry)) {
                throw new PermissionDeniedException();
            }
        }

        if (array_key_exists('content', $body)) {
            $source = (string) $body['content'];
            $this->checkLength($source);
            if (trim($source) === '' && $entry->media()->count() === 0 && $entry->repost_of_id === null) {
                $this->fail('content', 'empty');
            }
            $before = $this->content->mentionedUserIds($entry->content);
            $entry->content = $this->content->parse($source, $entry, $actor);
            $entry->search_text = $this->searchText($entry->content);
            $entry->edited_at = Carbon::now();
            $changed = true;
            $newly = array_diff($this->content->mentionedUserIds($entry->content), $before);
        }

        if (array_key_exists('privacy', $body) && $entry->isRoot()) {
            $privacy = (string) $body['privacy'];
            if (! in_array($privacy, Entry::PRIVACIES, true) || ($privacy === Entry::FOLLOWERS && ! $this->follows->enabled())) {
                $this->fail('privacy', 'bad_privacy');
            }
            if ($privacy !== $entry->privacy) {
                $entry->privacy = $privacy;
                $changed = true;
            }
        }

        if (array_key_exists('isLocked', $body)) {
            if (! $this->access->canLock($actor, $entry)) {
                throw new PermissionDeniedException();
            }
            $entry->locked_at = $body['isLocked'] ? ($entry->locked_at ?? Carbon::now()) : null;
            $changed = true;
        }

        if (array_key_exists('isHidden', $body)) {
            if (! $this->access->canHide($actor)) {
                throw new PermissionDeniedException();
            }
            $entry->hidden_at = $body['isHidden'] ? ($entry->hidden_at ?? Carbon::now()) : null;
            $entry->hidden_user_id = $body['isHidden'] ? $actor->id : null;
            $changed = true;
        }

        if ($changed) {
            $this->db->transaction(function () use ($entry) {
                $entry->save();
                // Replies carry their moment's privacy (see Visibility).
                if ($entry->isRoot() && $entry->wasChanged('privacy')) {
                    Entry::query()->where('root_id', $entry->id)->update(['privacy' => $entry->privacy]);
                }
            });
            $this->topics->sync($entry);
            $this->recountFor($entry);
            if (! empty($newly)) {
                $this->notifier->mentioned($entry, $actor, array_values($newly));
            }
        }

        return $entry;
    }

    public function delete(User $actor, Entry $entry): void
    {
        if (! $this->access->canDelete($actor, $entry)) {
            throw new PermissionDeniedException();
        }

        $ids = $this->withDescendants($entry);
        $topicIds = $this->topics->topicIdsOf($entry->id);
        $paths = Media::query()->where('source', 'local')->whereNotNull('path')->whereIn('entry_id', $ids)->pluck('path')->all();

        $this->db->transaction(function () use ($entry, $ids) {
            // Spelled out rather than left to the foreign keys, so a database
            // running without them enforced is cleaned up the same way.
            foreach (['moments_media', 'moments_reactions', 'moments_bookmarks', 'moments_entry_topic', 'moments_reports'] as $table) {
                $this->db->table($table)->whereIn('entry_id', $ids)->delete();
            }
            // A repost without a comment has nothing left to show; one with a
            // comment stays, as a moment of its own.
            Entry::query()->where('repost_of_id', $entry->id)->whereNull('content')->delete();
            Entry::query()->where('repost_of_id', $entry->id)->update(['repost_of_id' => null]);
            // Deepest first, so no row outlives the row it answers.
            Entry::query()->whereIn('id', $ids)->orderByDesc('depth')->orderByDesc('id')->get()->each(fn (Entry $e) => $e->delete());
        });

        $this->storage->deletePaths($paths);
        $this->topics->recount($topicIds);
        $this->recountFor($entry);
    }

    /** @return list<int> the entry and every reply beneath it */
    protected function withDescendants(Entry $entry): array
    {
        if ($entry->isRoot()) {
            return Entry::query()->where('root_id', $entry->id)->pluck('id')->map(fn ($id) => (int) $id)->push($entry->id)->all();
        }

        $rows = Entry::query()->where('root_id', $entry->root_id)->get(['id', 'parent_id']);
        $children = [];
        foreach ($rows as $row) {
            $children[(int) $row->parent_id][] = (int) $row->id;
        }
        $ids = [$entry->id];
        for ($i = 0; $i < count($ids); $i++) {
            foreach ($children[$ids[$i]] ?? [] as $child) {
                $ids[] = $child;
            }
        }

        return $ids;
    }

    public function react(User $actor, Entry $entry, ?string $reaction): void
    {
        $this->db->table('moments_reactions')->where('entry_id', $entry->id)->where('user_id', $actor->id)->delete();

        if ($reaction !== null) {
            $this->db->table('moments_reactions')->insert([
                'entry_id' => $entry->id,
                'user_id' => $actor->id,
                'reaction' => $reaction,
                'created_at' => Carbon::now(),
            ]);
        }

        $count = $this->db->table('moments_reactions')->where('entry_id', $entry->id)->count();
        Entry::query()->whereKey($entry->id)->update(['reaction_count' => $count]);
        $entry->reaction_count = $count;

        if ($reaction !== null) {
            $this->notifier->reacted($entry, $actor, $count);
        }
    }

    public function bookmark(User $actor, Entry $entry, bool $on): void
    {
        $this->db->table('moments_bookmarks')->where('user_id', $actor->id)->where('entry_id', $entry->id)->delete();
        if ($on) {
            $this->db->table('moments_bookmarks')->insert(['user_id' => $actor->id, 'entry_id' => $entry->id, 'created_at' => Carbon::now()]);
        }
    }

    public function report(User $actor, Entry $entry, string $reason, ?string $detail): void
    {
        if (! $this->access->canReport($actor, $entry)) {
            throw new PermissionDeniedException();
        }
        if (! in_array($reason, ['spam', 'inappropriate', 'off_topic', 'other'], true)) {
            $this->fail('reason', 'bad_reason');
        }

        $this->db->table('moments_reports')->insertOrIgnore([
            'entry_id' => $entry->id,
            'user_id' => $actor->id,
            'reason' => $reason,
            'detail' => $detail !== null ? mb_substr(trim($detail), 0, 1000) : null,
            'created_at' => Carbon::now(),
        ]);
    }

    public function dismissReports(User $actor, Entry $entry): void
    {
        if (! Access::canModerate($actor)) {
            throw new PermissionDeniedException();
        }
        $this->db->table('moments_reports')->where('entry_id', $entry->id)->delete();
    }

    protected function checkLength(string $source): void
    {
        if (mb_strlen(trim($source)) > $this->settings->charLimit()) {
            $this->fail('content', 'too_long', ['{max}' => $this->settings->charLimit()]);
        }
    }

    protected function searchText(?string $xml): ?string
    {
        $text = mb_strtolower($this->content->plain($xml));

        return $text === '' ? null : mb_substr($text, 0, 6000);
    }

    /**
     * Counters recomputed, never incremented: a moment's replies (every
     * visible reply under it), a reply's direct replies, a source's reposts.
     */
    protected function recountFor(Entry $entry): void
    {
        if ($entry->root_id !== null) {
            Entry::query()->whereKey($entry->root_id)->update([
                'reply_count' => Entry::query()->where('root_id', $entry->root_id)->whereNull('hidden_at')->count(),
            ]);
            if ($entry->parent_id !== null && $entry->parent_id !== $entry->root_id) {
                Entry::query()->whereKey($entry->parent_id)->update([
                    'reply_count' => Entry::query()->where('parent_id', $entry->parent_id)->whereNull('hidden_at')->count(),
                ]);
            }
        }

        if ($entry->repost_of_id !== null) {
            Entry::query()->whereKey($entry->repost_of_id)->update([
                'repost_count' => Entry::query()->where('repost_of_id', $entry->repost_of_id)->count(),
            ]);
        }
    }
}
