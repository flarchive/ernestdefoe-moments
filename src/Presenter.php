<?php

namespace Ernestdefoe\Moments;

use Flarum\Http\SlugManager;
use Flarum\Http\UrlGenerator;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Everything Moments sends to a browser is built here, field by field.
 *
 * 🚨 A fixed number of queries for any number of moments: authors, media,
 * repost sources, the reaction breakdown, the reader's own reactions,
 * bookmarks and reposts, report counts, mentions and link cards are each
 * loaded once for the whole page (see QueryCountTest).
 */
class Presenter
{
    /** @var array<int, User> */
    protected array $users = [];

    public function __construct(
        protected ConnectionInterface $db,
        protected Content $content,
        protected Embeds $embeds,
        protected Visibility $visibility,
        protected Access $access,
        protected SlugManager $slugs,
        protected UrlGenerator $url
    ) {
    }

    /** The relations a page of moments is loaded with. */
    public function with(User $actor): array
    {
        return [
            'user',
            'media',
            // The privacy rule is Entry's visibility scoper, so this is it.
            'repostOf' => fn ($q) => $q->whereVisibleTo($actor),
            'repostOf.user',
            'repostOf.media',
        ];
    }

    /**
     * @param Collection<int, Entry> $entries loaded with `with()`
     * @param array<int, Entry> $roots moments the replies belong to, by id, for reply permissions
     * @return list<array<string, mixed>>
     */
    public function entries(Collection $entries, User $actor, ?ServerRequestInterface $request = null, array $roots = []): array
    {
        if ($entries->isEmpty()) {
            return [];
        }

        $sources = $entries->pluck('repostOf')->filter();
        $all = $entries->concat($sources)->unique('id')->values();
        $ids = $all->pluck('id')->map(fn ($id) => (int) $id)->all();

        $breakdown = [];
        foreach ($this->db->table('moments_reactions')->whereIn('entry_id', $ids)
            ->select('entry_id', 'reaction')->selectRaw('COUNT(*) as aggregate')
            ->groupBy('entry_id', 'reaction')->get() as $row) {
            $breakdown[(int) $row->entry_id][] = ['key' => (string) $row->reaction, 'count' => (int) $row->aggregate];
        }

        $mine = $bookmarks = $reposted = $reports = [];
        if (! $actor->isGuest()) {
            $mine = $this->db->table('moments_reactions')->where('user_id', $actor->id)->whereIn('entry_id', $ids)->pluck('reaction', 'entry_id')->all();
            $bookmarks = array_flip($this->db->table('moments_bookmarks')->where('user_id', $actor->id)->whereIn('entry_id', $ids)->pluck('entry_id')->map(fn ($v) => (int) $v)->all());
            $reposted = array_flip($this->db->table('moments_entries')->where('user_id', $actor->id)->whereIn('repost_of_id', $ids)->pluck('repost_of_id')->map(fn ($v) => (int) $v)->all());
            if (Access::canModerate($actor)) {
                $reports = $this->db->table('moments_reports')->whereIn('entry_id', $ids)->groupBy('entry_id')
                    ->select('entry_id')->selectRaw('COUNT(*) as aggregate')->pluck('aggregate', 'entry_id')->all();
            }
        }

        $this->content->prime($all);
        $cards = $this->embeds->forEntries($all, $actor);

        $context = compact('breakdown', 'mine', 'bookmarks', 'reposted', 'reports', 'cards', 'roots');

        return $entries->map(function (Entry $entry) use ($actor, $request, $context) {
            $data = $this->one($entry, $actor, $request, $context);

            if ($entry->repost_of_id !== null) {
                $source = $entry->repostOf;
                $data['repostOf'] = $source
                    ? $this->one($source, $actor, $request, $context)
                    : ['id' => $entry->repost_of_id, 'unavailable' => true];
            }

            return $data;
        })->values()->all();
    }

    /** @param array<string, mixed> $c */
    protected function one(Entry $entry, User $actor, ?ServerRequestInterface $request, array $c): array
    {
        if ($entry->user) {
            $this->users[$entry->user->id] = $entry->user;
        }

        $root = $entry->isRoot() ? $entry : ($c['roots'][$entry->root_id] ?? null);
        $moderator = Access::canModerate($actor);

        return [
            'id' => $entry->id,
            'userId' => $entry->user_id,
            'rootId' => $entry->root_id,
            'parentId' => $entry->parent_id,
            'threadId' => $entry->thread_id,
            'depth' => $entry->depth,
            'html' => $this->content->render($entry->content, $entry, $request),
            'privacy' => $entry->privacy,
            'createdAt' => $entry->created_at?->toIso8601String(),
            'editedAt' => $entry->edited_at?->toIso8601String(),
            'isHidden' => $entry->hidden_at !== null,
            'isLocked' => $entry->locked_at !== null,
            'media' => $entry->media->map(fn (Media $m) => $m->present())->values()->all(),
            'replyCount' => $entry->reply_count,
            'repostCount' => $entry->repost_count,
            'reactionCount' => $entry->reaction_count,
            'reactions' => $c['breakdown'][$entry->id] ?? [],
            'myReaction' => $c['mine'][$entry->id] ?? null,
            'bookmarked' => isset($c['bookmarks'][$entry->id]),
            'reposted' => isset($c['reposted'][$entry->id]),
            'reportCount' => $moderator ? (int) ($c['reports'][$entry->id] ?? 0) : null,
            'embeds' => $c['cards'][$entry->id] ?? [],
            'url' => $this->url->to('forum')->route('moments.show', ['id' => $entry->id]),
            'can' => [
                'edit' => $this->access->canEdit($actor, $entry),
                'delete' => $this->access->canDelete($actor, $entry),
                'hide' => $this->access->canHide($actor),
                'lock' => $this->access->canLock($actor, $entry),
                'reply' => $root ? $this->access->canReply($actor, $root) : false,
                'report' => $this->access->canReport($actor, $entry),
                'repost' => $this->access->canRepost($actor, $entry),
                'react' => ! $actor->isGuest() && Access::canView($actor) && $entry->hidden_at === null,
            ],
        ];
    }

    /** The source a member edits from. */
    public function editable(Entry $entry): array
    {
        return ['id' => $entry->id, 'source' => $this->content->source($entry->content, $entry), 'privacy' => $entry->privacy];
    }

    /**
     * The authors of everything presented so far, as JSON:API user resources
     * the forum's store takes as they are.
     *
     * @return list<array<string, mixed>>
     */
    public function includedUsers(): array
    {
        $driver = $this->slugs->forResource(User::class);

        return array_values(array_map(fn (User $user) => [
            'type' => 'users',
            'id' => (string) $user->id,
            'attributes' => [
                'username' => $user->username,
                'displayName' => $user->display_name,
                'avatarUrl' => $user->avatar_url,
                'slug' => $driver->toSlug($user),
            ],
        ], $this->users));
    }
}
