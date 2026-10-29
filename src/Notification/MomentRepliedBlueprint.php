<?php

namespace Ernestdefoe\Moments\Notification;

class MomentRepliedBlueprint extends MomentBlueprint
{
    public static function getType(): string
    {
        return 'momentReplied';
    }

    public function emailKey(): string
    {
        return 'replied';
    }
}
