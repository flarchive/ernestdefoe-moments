<?php

namespace Ernestdefoe\Moments\Api\Resource;

use Ernestdefoe\Moments\Content;
use Ernestdefoe\Moments\Entry;
use Ernestdefoe\Moments\Visibility;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * The `moments` type, for the forum's notification list only: a notification's
 * subject is serialised through it. It has no endpoints of its own — moments
 * are read through Moments' own API — and its scope is the privacy rule.
 *
 * @extends AbstractDatabaseResource<Entry>
 */
class MomentResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'moments';
    }

    public function model(): string
    {
        return Entry::class;
    }

    /** @param \Flarum\Api\Context $context */
    public function scope(Builder $query, \Tobyz\JsonApiServer\Context $context): void
    {
        resolve(Visibility::class)->scope($query, $context->getActor());
    }

    public function endpoints(): array
    {
        return [];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('excerpt')
                ->get(fn (Entry $entry) => Str::limit(resolve(Content::class)->plain($entry->content), 200)),
            Schema\Integer::make('rootId')
                ->get(fn (Entry $entry) => $entry->rootId()),
            Schema\Boolean::make('isReply')
                ->get(fn (Entry $entry) => ! $entry->isRoot()),
            Schema\DateTime::make('createdAt'),
        ];
    }
}
