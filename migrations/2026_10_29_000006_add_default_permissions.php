<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

return Migration::addPermissions([
    'moments.view' => Group::GUEST_ID,
    'moments.post' => Group::MEMBER_ID,
    'moments.reply' => Group::MEMBER_ID,
    'moments.uploadMedia' => Group::MEMBER_ID,
    'moments.recordVoice' => Group::MEMBER_ID,
    'moments.useGifs' => Group::MEMBER_ID,
    'moments.moderate' => Group::MODERATOR_ID,
]);
