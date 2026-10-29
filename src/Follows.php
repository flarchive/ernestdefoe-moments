<?php

namespace Ernestdefoe\Moments;

use Flarum\Extension\ExtensionManager;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Following, from ianm/follow-users when it is enabled: its `user_followers`
 * table (user_id follows followed_user_id) is read directly, through the
 * query builder so the table prefix is applied. Without it there is no
 * Following feed, and a followers-only moment is seen by its author alone.
 */
class Follows
{
    protected ?bool $enabled = null;

    public function __construct(protected ExtensionManager $extensions, protected ConnectionInterface $db)
    {
    }

    public function enabled(): bool
    {
        return $this->enabled ??= $this->extensions->isEnabled('ianm-follow-users');
    }

    /** A subquery of the ids the member follows, for whereIn. */
    public function followedBy(int $userId): \Closure
    {
        return function (Builder $query) use ($userId) {
            $query->select('followed_user_id')->from('user_followers')->where('user_id', $userId);
        };
    }

    public function follows(int $followerId, int $userId): bool
    {
        return $this->enabled()
            && $this->db->table('user_followers')->where('user_id', $followerId)->where('followed_user_id', $userId)->exists();
    }

    /** @return list<int> the member's followers */
    public function followersOf(int $userId): array
    {
        if (! $this->enabled()) {
            return [];
        }

        return array_map('intval', $this->db->table('user_followers')->where('followed_user_id', $userId)->pluck('user_id')->all());
    }
}
