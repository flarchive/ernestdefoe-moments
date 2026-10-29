<?php

namespace Ernestdefoe\Moments;

use Carbon\Carbon;
use Flarum\User\User;

/** Who may do what. Every controller asks here, so the rules live in one place. */
class Access
{
    public function __construct(protected Settings $settings)
    {
    }

    public static function canView(User $actor): bool
    {
        return $actor->hasPermission('moments.view');
    }

    public static function canModerate(User $actor): bool
    {
        return ! $actor->isGuest() && $actor->hasPermission('moments.moderate');
    }

    public static function canPost(User $actor): bool
    {
        return ! $actor->isGuest() && $actor->hasPermission('moments.view') && $actor->hasPermission('moments.post');
    }

    public static function canReplyAtAll(User $actor): bool
    {
        return ! $actor->isGuest() && $actor->hasPermission('moments.view') && $actor->hasPermission('moments.reply');
    }

    /** Replying to a visible moment: not when it is locked, unless a moderator. */
    public function canReply(User $actor, Entry $root): bool
    {
        if (! self::canReplyAtAll($actor) || $root->hidden_at !== null) {
            return false;
        }

        return $root->locked_at === null || self::canModerate($actor);
    }

    public function canEdit(User $actor, Entry $entry): bool
    {
        if ($actor->isGuest() || $entry->user_id !== $actor->id || $entry->hidden_at !== null) {
            return false;
        }

        $window = $this->settings->editWindow();

        return $window === 0 || ($entry->created_at && $entry->created_at->gt(Carbon::now()->subMinutes($window)));
    }

    public function canDelete(User $actor, Entry $entry): bool
    {
        return ! $actor->isGuest() && ($entry->user_id === $actor->id || self::canModerate($actor));
    }

    public function canHide(User $actor): bool
    {
        return self::canModerate($actor);
    }

    /** Locking replies: the moment's author or a moderator. */
    public function canLock(User $actor, Entry $entry): bool
    {
        return $entry->isRoot() && ! $actor->isGuest() && ($entry->user_id === $actor->id || self::canModerate($actor));
    }

    public function canReport(User $actor, Entry $entry): bool
    {
        return ! $actor->isGuest() && $entry->user_id !== $actor->id && self::canView($actor);
    }

    /** Only public moments can be reposted, so a repost never widens an audience. */
    public function canRepost(User $actor, Entry $entry): bool
    {
        return self::canPost($actor) && $entry->isRoot() && $entry->privacy === Entry::PUBLIC
            && $entry->hidden_at === null && $entry->repost_of_id === null;
    }

    public static function canUploadMedia(User $actor): bool
    {
        return ! $actor->isGuest() && $actor->hasPermission('moments.uploadMedia');
    }

    public static function canRecordVoice(User $actor): bool
    {
        return ! $actor->isGuest() && $actor->hasPermission('moments.recordVoice');
    }

    public static function canUseGifs(User $actor): bool
    {
        return ! $actor->isGuest() && $actor->hasPermission('moments.useGifs');
    }
}
