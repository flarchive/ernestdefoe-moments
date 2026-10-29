<?php

namespace Ernestdefoe\Moments;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property int|null $entry_id
 * @property int $user_id
 * @property string $kind image|video|audio|gif
 * @property string $source local|fof|giphy
 * @property string|null $path
 * @property string $url
 * @property string|null $mime
 * @property int|null $width
 * @property int|null $height
 * @property int|null $duration milliseconds
 * @property int|null $size
 * @property int $position
 * @property Carbon|null $created_at
 */
class Media extends AbstractModel
{
    public const KINDS = ['image', 'video', 'audio', 'gif'];

    protected $table = 'moments_media';

    public $timestamps = false;

    protected $casts = [
        'entry_id' => 'integer',
        'user_id' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'duration' => 'integer',
        'size' => 'integer',
        'position' => 'integer',
        'created_at' => 'datetime',
    ];

    /** @return array<string, mixed> */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'url' => $this->url,
            'mime' => $this->mime,
            'width' => $this->width,
            'height' => $this->height,
            'duration' => $this->duration,
        ];
    }
}
