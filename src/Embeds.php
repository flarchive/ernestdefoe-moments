<?php

namespace Ernestdefoe\Moments;

use Flarum\Discussion\Discussion;
use Flarum\Http\SlugManager;
use Flarum\Http\UrlGenerator;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Support\Str;

/**
 * Pasted links to this forum's discussions, posts, members and moments become
 * cards. They are looked up as the person READING, through each model's own
 * visibility, so a card never shows what its reader could not open — a
 * private discussion linked in a public moment is just a link. External
 * links stay plain links: nothing is ever fetched from another site.
 *
 * One query per kind of link for a whole page of moments, and none when no
 * moment links here.
 */
class Embeds
{
    public const MAX_PER_ENTRY = 2;

    public function __construct(
        protected UrlGenerator $url,
        protected SlugManager $slugs,
        protected Content $content,
        protected Visibility $visibility
    ) {
    }

    /**
     * @param iterable<Entry> $entries
     * @return array<int, list<array<string, mixed>>> entry id => cards
     */
    public function forEntries(iterable $entries, User $actor): array
    {
        $wanted = [];
        $refs = ['d' => [], 'p' => [], 'u' => [], 'm' => []];

        foreach ($entries as $entry) {
            foreach (array_slice($this->content->links($entry->content), 0, self::MAX_PER_ENTRY * 2) as $link) {
                $ref = $this->parse($link);
                if ($ref === null) {
                    continue;
                }
                if (count($wanted[$entry->id] ?? []) >= self::MAX_PER_ENTRY) {
                    break;
                }
                $wanted[$entry->id][] = [$ref, $link];
                $refs[$ref[0]][] = $ref;
            }
        }

        if (! $wanted) {
            return [];
        }

        $cards = $this->resolve($refs, $actor);
        $out = [];

        foreach ($wanted as $entryId => $list) {
            foreach ($list as [$ref, $link]) {
                $key = implode(':', $ref);
                if (isset($cards[$key])) {
                    $out[$entryId][] = $cards[$key] + ['url' => $link];
                }
            }
        }

        return $out;
    }

    /** @return array{0: string, 1: string, 2?: string}|null */
    public function parse(string $link): ?array
    {
        // Control characters stripped before anything is decided.
        $link = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $link);
        $base = rtrim($this->url->to('forum')->base(), '/');

        if (! str_starts_with(strtolower($link), strtolower($base).'/')) {
            return null;
        }

        $path = (string) parse_url(substr($link, strlen($base)), PHP_URL_PATH);

        if (preg_match('~^/d/(\d{1,10})(?:-[^/]*)?/?$~', $path, $m)) {
            return ['d', $m[1]];
        }
        if (preg_match('~^/d/(\d{1,10})(?:-[^/]*)?/(\d{1,10})/?$~', $path, $m)) {
            return $m[2] === '1' ? ['d', $m[1]] : ['p', $m[1], $m[2]];
        }
        if (preg_match('~^/u/([^/]{1,100})/?$~', $path, $m)) {
            return ['u', rawurldecode($m[1])];
        }
        if (preg_match('~^/moments/(\d{1,10})/?$~', $path, $m)) {
            return ['m', $m[1]];
        }

        return null;
    }

    /**
     * @param array<string, list<array<int, string>>> $refs
     * @return array<string, array<string, mixed>>
     */
    protected function resolve(array $refs, User $actor): array
    {
        $cards = [];

        $discussionIds = array_unique(array_map(fn ($r) => (int) $r[1], $refs['d']));
        if ($discussionIds) {
            $discussions = Discussion::query()->whereVisibleTo($actor)->whereIn('id', $discussionIds)
                ->with(['firstPost' => fn ($q) => $q->whereVisibleTo($actor), 'user'])->get();
            foreach ($discussions as $d) {
                $cards['d:'.$d->id] = [
                    'type' => 'discussion',
                    'title' => $d->title,
                    'excerpt' => $d->firstPost ? Str::limit($this->postText($d->firstPost), 160) : null,
                    'author' => $d->user?->display_name,
                    'replies' => max(0, (int) $d->comment_count - 1),
                ];
            }
        }

        if ($refs['p']) {
            $query = Post::query()->whereVisibleTo($actor)->where('type', 'comment')->with(['discussion', 'user']);
            $query->where(function ($q) use ($refs) {
                foreach ($refs['p'] as $r) {
                    $q->orWhere(fn ($q) => $q->where('discussion_id', (int) $r[1])->where('number', (int) $r[2]));
                }
            });
            foreach ($query->limit(50)->get() as $post) {
                if (! $post->discussion) {
                    continue;
                }
                $cards['p:'.$post->discussion_id.':'.$post->number] = [
                    'type' => 'post',
                    'title' => $post->discussion->title,
                    'excerpt' => Str::limit($this->postText($post), 160),
                    'author' => $post->user?->display_name,
                ];
            }
        }

        if ($refs['u']) {
            $slugs = array_values(array_unique(array_map(fn ($r) => $r[1], $refs['u'])));
            $users = User::query()->whereVisibleTo($actor)->where(function ($q) use ($slugs) {
                $q->whereIn('username', $slugs);
                $ids = array_filter($slugs, 'ctype_digit');
                if ($ids) {
                    $q->orWhereIn('id', $ids);
                }
            })->limit(50)->get();
            foreach ($users as $user) {
                $card = [
                    'type' => 'user',
                    'title' => $user->display_name,
                    'username' => $user->username,
                    'avatarUrl' => $user->avatar_url,
                ];
                $cards['u:'.$user->username] = $card;
                $cards['u:'.$user->id] = $card;
            }
        }

        $momentIds = array_unique(array_map(fn ($r) => (int) $r[1], $refs['m']));
        if ($momentIds) {
            $entries = $this->visibility->entries($actor)->whereIn('id', $momentIds)->with('user')->get();
            foreach ($entries as $entry) {
                $cards['m:'.$entry->id] = [
                    'type' => 'moment',
                    'title' => $entry->user?->display_name,
                    'excerpt' => Str::limit($this->content->plain($entry->content), 160),
                    'avatarUrl' => $entry->user?->avatar_url,
                ];
            }
        }

        return $cards;
    }

    protected function postText(Post $post): string
    {
        return $this->content->plain(is_string($post->getAttributes()['content'] ?? null) ? $post->getAttributes()['content'] : null);
    }
}
