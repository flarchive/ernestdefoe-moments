<?php

namespace Ernestdefoe\Moments;

use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * 🚨 The one privacy rule. Every read path — feeds, a single moment, the
 * profile tab, replies, search, widgets, the new-moments count, link cards,
 * notifications and emails — goes through this scope or `canSee()`, which
 * asks the same question of one row.
 *
 *  - public: anyone with the view permission
 *  - followers: the author and the author's followers (with ianm/follow-users;
 *    without it, the author alone), and moderators, who must be able to read
 *    what they are asked to review
 *  - private: the author alone, moderators included
 *
 * A reply carries its moment's privacy and author (`audience_user_id`), so it
 * is seen by exactly the people who can see the moment. Hidden moments are
 * seen by their author and moderators, and so are the replies under them.
 *
 * Registered as the model's visibility scoper, so core's notification list
 * drops a notification whose moment its reader can no longer see.
 */
class Visibility
{
    public function __construct(protected Follows $follows)
    {
    }

    public function __invoke(User $actor, Builder $query): void
    {
        $this->scope($query, $actor);
    }

    /** @return Builder<Entry> the moments and replies the actor may see */
    public function entries(User $actor): Builder
    {
        return $this->scope(Entry::query(), $actor);
    }

    /**
     * @template TBuilder of Builder
     * @param TBuilder $query
     * @return TBuilder
     */
    public function scope(Builder $query, User $actor): Builder
    {
        $table = $query->getModel()->getTable();

        if (! Access::canView($actor)) {
            return $query->whereRaw('1 = 0');
        }

        $moderator = Access::canModerate($actor);

        $query->where(function (Builder $q) use ($actor, $table, $moderator) {
            $q->where("$table.privacy", Entry::PUBLIC);

            if ($actor->isGuest()) {
                return;
            }

            $q->orWhere("$table.audience_user_id", $actor->id);

            if ($moderator) {
                $q->orWhere("$table.privacy", Entry::FOLLOWERS);
            } elseif ($this->follows->enabled()) {
                $q->orWhere(function (Builder $q) use ($actor, $table) {
                    $q->where("$table.privacy", Entry::FOLLOWERS)
                        ->whereIn("$table.audience_user_id", $this->follows->followedBy($actor->id));
                });
            }
        });

        if (! $moderator) {
            $query->where(function (Builder $q) use ($actor, $table) {
                $q->whereNull("$table.hidden_at");
                if (! $actor->isGuest()) {
                    $q->orWhere("$table.user_id", $actor->id);
                }
            });

            // A reply hides with its moment: only the moment's author (and
            // moderators) still see it. One indexed subquery, no extra query.
            $query->where(function (Builder $q) use ($actor, $table) {
                $q->whereNull("$table.root_id")
                    ->orWhereNotIn("$table.root_id", fn ($s) => $s->select('id')->from('moments_entries')->whereNotNull('hidden_at'));
                if (! $actor->isGuest()) {
                    $q->orWhere("$table.audience_user_id", $actor->id);
                }
            });
        }

        return $query;
    }

    /** @var array<int, bool> root id => hidden, asked once per root (canSee runs per recipient) */
    protected array $hiddenRoots = [];

    protected function rootHidden(int $rootId): bool
    {
        return $this->hiddenRoots[$rootId] ??= Entry::query()->whereKey($rootId)->whereNotNull('hidden_at')->exists();
    }

    /** The same rule for one loaded row. */
    public function canSee(User $actor, Entry $entry): bool
    {
        if (! Access::canView($actor)) {
            return false;
        }

        $moderator = Access::canModerate($actor);
        $mine = ! $actor->isGuest() && $entry->audience_user_id === $actor->id;

        if ($entry->hidden_at !== null && ! $moderator && $entry->user_id !== $actor->id) {
            return false;
        }

        if (! $moderator && ! $mine && ! $entry->isRoot() && $this->rootHidden((int) $entry->root_id)) {
            return false;
        }

        return match ($entry->privacy) {
            Entry::PUBLIC => true,
            Entry::FOLLOWERS => $mine || $moderator || (! $actor->isGuest() && $this->follows->follows($actor->id, $entry->audience_user_id)),
            default => $mine,
        };
    }
}
