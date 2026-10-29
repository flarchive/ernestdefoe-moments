<?php

namespace Ernestdefoe\Moments;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Database\ScopeVisibilityTrait;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A moment (parent_id null) or a reply to one.
 *
 * @property int $id
 * @property int $user_id
 * @property int $audience_user_id
 * @property int|null $root_id
 * @property int|null $parent_id
 * @property int|null $thread_id
 * @property int $depth
 * @property int|null $repost_of_id
 * @property string|null $content
 * @property string|null $search_text
 * @property string $privacy
 * @property int $reply_count
 * @property int $repost_count
 * @property int $reaction_count
 * @property Carbon|null $locked_at
 * @property Carbon|null $hidden_at
 * @property int|null $hidden_user_id
 * @property Carbon|null $edited_at
 * @property Carbon|null $created_at
 * @property-read User|null $user
 * @property-read Entry|null $repostOf
 * @property-read Entry|null $root
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Media> $media
 */
class Entry extends AbstractModel
{
    use ScopeVisibilityTrait;

    public const PUBLIC = 'public';
    public const FOLLOWERS = 'followers';
    public const PRIVATE = 'private';
    public const PRIVACIES = [self::PUBLIC, self::FOLLOWERS, self::PRIVATE];

    protected $table = 'moments_entries';

    public $timestamps = false;

    protected $casts = [
        'user_id' => 'integer',
        'audience_user_id' => 'integer',
        'root_id' => 'integer',
        'parent_id' => 'integer',
        'thread_id' => 'integer',
        'depth' => 'integer',
        'repost_of_id' => 'integer',
        'reply_count' => 'integer',
        'repost_count' => 'integer',
        'reaction_count' => 'integer',
        'hidden_user_id' => 'integer',
        'locked_at' => 'datetime',
        'hidden_at' => 'datetime',
        'edited_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /** The moment a reply belongs to; the moment itself for a moment. */
    public function rootId(): int
    {
        return $this->root_id ?? $this->id;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Entry, $this> */
    public function repostOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'repost_of_id');
    }

    /** @return BelongsTo<Entry, $this> */
    public function root(): BelongsTo
    {
        return $this->belongsTo(self::class, 'root_id');
    }

    /** @return HasMany<Media, $this> */
    public function media(): HasMany
    {
        return $this->hasMany(Media::class, 'entry_id')->orderBy('position');
    }
}
