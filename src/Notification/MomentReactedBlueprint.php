<?php

namespace Ernestdefoe\Moments\Notification;

class MomentReactedBlueprint extends MomentBlueprint
{
    public static function getType(): string
    {
        return 'momentReacted';
    }

    public function emailKey(): string
    {
        return 'reacted';
    }
}
