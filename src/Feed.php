<?php

namespace Ernestdefoe\Moments;

use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The moments in a feed: always top-level moments (replies live in their
 * threads), always through the privacy rule, newest first, paged by cursor
 * (`before` = the last id seen) so a page never repeats or skips a moment
 * that arrived while you scrolled.
 */
class Feed
{
    public const FEEDS = ['everyone', 'following', 'bookmarks', 'topic', 'user', 'reported', 'mine'];

    public function __construct(protected Visibility $visibility, protected Follows $follows)
    {
    }

    /**
     * @param array<string, mixed> $filter
     * @return Builder<Entry>|null null when the feed is not one this reader can have
     */
    public function query(User $actor, array $filter): ?Builder
    {
        $feed = in_array($filter['feed'] ?? null, self::FEEDS, true) ? $filter['feed'] : 'everyone';
        $query = $this->visibility->entries($actor)->whereNull('moments_entries.parent_id');

        switch ($feed) {
            case 'following':
                if ($actor->isGuest() || ! $this->follows->enabled()) {
                    return null;
                }
                $query->where(function (Builder $q) use ($actor) {
                    $q->whereIn('moments_entries.user_id', $this->follows->followedBy($actor->id))
                        ->orWhere('moments_entries.user_id', $actor->id);
                });
                break;

            case 'bookmarks':
                if ($actor->isGuest()) {
                    return null;
                }
                $query->whereIn('moments_entries.id', fn ($q) => $q->select('entry_id')->from('moments_bookmarks')->where('user_id', $actor->id));
                break;

            case 'topic':
                $key = Topic::key(ltrim((string) ($filter['topic'] ?? ''), '#'));
                if ($key === '' || mb_strlen($key) > 60) {
                    return null;
                }
                $query->whereIn('moments_entries.id', fn ($q) => $q->select('moments_entry_topic.entry_id')->from('moments_entry_topic')
                    ->join('moments_topics', 'moments_topics.id', '=', 'moments_entry_topic.topic_id')
                    ->where('moments_topics.name_key', $key));
                break;

            case 'user':
                $userId = (int) ($filter['user'] ?? 0);
                // The profile owner must be someone this reader can see.
                if ($userId < 1 || ! User::query()->whereVisibleTo($actor)->whereKey($userId)->exists()) {
                    return null;
                }
                $query->where('moments_entries.user_id', $userId);
                break;

            case 'mine':
                if ($actor->isGuest()) {
                    return null;
                }
                $query->where('moments_entries.user_id', $actor->id);
                break;

            case 'reported':
                if (! Access::canModerate($actor)) {
                    return null;
                }
                // Reported replies are shown too: a moderator reviews them here.
                $query = $this->visibility->entries($actor)
                    ->whereIn('moments_entries.id', fn ($q) => $q->select('entry_id')->from('moments_reports'));
                break;
        }

        if (is_string($q = $filter['q'] ?? null) && ($q = trim($q)) !== '') {
            $this->search($query, $q);
        }

        return $query->orderByDesc('moments_entries.id');
    }

    /**
     * Words in any order, case-insensitive. MySQL and MariaDB use the
     * FULLTEXT index on search_text; PostgreSQL and SQLite a LIKE per word.
     * A `#topic` in the search narrows to that topic through its index.
     */
    protected function search(Builder $query, string $q): void
    {
        $q = mb_substr($q, 0, 100);

        if (preg_match_all('/(?:^|\s)#([-_\p{L}\p{N}\p{M}]{1,59})/u', $q, $m)) {
            foreach ($m[1] as $name) {
                $key = Topic::key($name);
                $query->whereIn('moments_entries.id', fn ($s) => $s->select('moments_entry_topic.entry_id')->from('moments_entry_topic')
                    ->join('moments_topics', 'moments_topics.id', '=', 'moments_entry_topic.topic_id')
                    ->where('moments_topics.name_key', $key));
            }
            $q = trim((string) preg_replace('/(?:^|\s)#[-_\p{L}\p{N}\p{M}]{1,59}/u', ' ', $q));
        }

        $words = array_slice(array_filter(preg_split('/\s+/u', mb_strtolower(str_replace(['%', '_', '\\', '"', '+', '-', '*', '<', '>', '(', ')', '~', '@'], ' ', $q))) ?: []), 0, 8);
        if (! $words) {
            // Only punctuation was typed: that matches nothing, not everything.
            if (empty($m[1])) {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        $driver = $query->getConnection()->getDriverName();
        $column = $query->getQuery()->getGrammar()->wrap('moments_entries.search_text');

        $long = array_values(array_filter($words, fn ($w) => mb_strlen($w) >= 3));
        if ($long && in_array($driver, ['mysql', 'mariadb'], true)) {
            $query->whereRaw("MATCH($column) AGAINST (? IN BOOLEAN MODE)", [implode(' ', array_map(fn ($w) => '+'.$w.'*', $long))]);
            $words = array_diff($words, $long);
        }

        foreach ($words as $word) {
            $query->whereRaw("$column LIKE ?", ['%'.$word.'%']);
        }
    }
}
