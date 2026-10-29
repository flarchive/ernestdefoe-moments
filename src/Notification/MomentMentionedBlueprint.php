<?php

namespace Ernestdefoe\Moments\Notification;

class MomentMentionedBlueprint extends MomentBlueprint
{
    public static function getType(): string
    {
        return 'momentMentioned';
    }

    public function emailKey(): string
    {
        return 'mentioned';
    }
}
