<?php

namespace Ernestdefoe\Moments;

use Ernestdefoe\Moments\Notification\MomentMentionedBlueprint;
use Ernestdefoe\Moments\Notification\MomentReactedBlueprint;
use Ernestdefoe\Moments\Notification\MomentRepliedBlueprint;
use Ernestdefoe\Moments\Notification\MomentRepostedBlueprint;
use Flarum\Notification\Notification;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;

/**
 * Who hears about what. 🚨 Every recipient is checked against the privacy
 * rule first: a member mentioned in a followers-only moment they can't see
 * gets nothing, by alert or by email.
 */
class Notifier
{
    public const MAX_MENTIONS = 20;

    public function __construct(protected NotificationSyncer $notifications, protected Visibility $visibility, protected Content $content)
    {
    }

    public function created(Entry $entry, User $actor, ?Entry $parent, ?Entry $root, ?Entry $repostOf): void
    {
        $told = [$actor->id];

        if ($parent) {
            $recipients = [];
            foreach (array_unique([$parent->user_id, $root?->user_id]) as $id) {
                if ($id && ! in_array($id, $told, true)) {
                    $recipients[] = $id;
                    $told[] = $id;
                }
            }
            $this->send(new MomentRepliedBlueprint($entry, $actor), $recipients, $entry);
        }

        if ($repostOf && $repostOf->user_id !== $actor->id) {
            $this->send(new MomentRepostedBlueprint($repostOf, $actor, ['repostId' => $entry->id]), [$repostOf->user_id], $repostOf);
        }

        $this->mentioned($entry, $actor, array_diff($this->content->mentionedUserIds($entry->content), $told));
    }

    /** @param array<int> $userIds */
    public function mentioned(Entry $entry, User $actor, array $userIds): void
    {
        $userIds = array_slice(array_values(array_diff($userIds, [$actor->id])), 0, self::MAX_MENTIONS);
        $this->send(new MomentMentionedBlueprint($entry, $actor), $userIds, $entry);
    }

    /**
     * Reactions are batched: the owner keeps one notification per moment,
     * replaced by the latest reaction ("Ada and 4 others reacted").
     */
    public function reacted(Entry $entry, User $actor, int $count): void
    {
        if ($entry->user_id === $actor->id) {
            return;
        }

        Notification::query()->where('type', MomentReactedBlueprint::getType())
            ->where('subject_id', $entry->id)->where('user_id', $entry->user_id)->delete();

        $this->send(new MomentReactedBlueprint($entry, $actor, ['count' => $count]), [$entry->user_id], $entry);
    }

    /** @param array<int> $userIds */
    protected function send(\Flarum\Notification\Blueprint\BlueprintInterface $blueprint, array $userIds, Entry $subject): void
    {
        if (! $userIds) {
            return;
        }

        $users = User::query()->whereIn('id', $userIds)->get()
            ->filter(fn (User $user) => $this->visibility->canSee($user, $subject))
            ->values()->all();

        if ($users) {
            $this->notifications->sync($blueprint, $users);
        }
    }
}
