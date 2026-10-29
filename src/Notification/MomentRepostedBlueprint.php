<?php

namespace Ernestdefoe\Moments\Notification;

class MomentRepostedBlueprint extends MomentBlueprint
{
    public static function getType(): string
    {
        return 'momentReposted';
    }

    public function emailKey(): string
    {
        return 'reposted';
    }
}
