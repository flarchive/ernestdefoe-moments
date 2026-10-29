<?php

namespace Ernestdefoe\Moments;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property string $name
 * @property string $name_key
 * @property int $moment_count
 * @property Carbon|null $last_used_at
 */
class Topic extends AbstractModel
{
    protected $table = 'moments_topics';

    public $timestamps = false;

    protected $casts = ['moment_count' => 'integer', 'last_used_at' => 'datetime'];

    /** Case-folded, so #GameDay and #gameday are one topic. */
    public static function key(string $name): string
    {
        return mb_strtolower(trim($name), 'UTF-8');
    }
}
