<?php

/*
 * Moments — a microblogging social feed for Flarum.
 *
 * The shape of the extension: short posts (entries) in their own table,
 * written through the forum's formatter, read through ONE privacy rule
 * (Visibility) on every path, and presented a page at a time with a fixed
 * number of queries (Presenter). Everything optional — mentions, hashtags,
 * reactions, uploads, following, realtime, widgets, SEO — is used when it is
 * there and not missed when it isn't.
 */

use Ernestdefoe\Moments\Access;
use Ernestdefoe\Moments\Api;
use Ernestdefoe\Moments\Console\PruneCommand;
use Ernestdefoe\Moments\Content\MomentDocument;
use Ernestdefoe\Moments\Entry;
use Ernestdefoe\Moments\Media\Storage;
use Ernestdefoe\Moments\Notification;
use Ernestdefoe\Moments\Seo\MomentPage;
use Ernestdefoe\Moments\Throttle;
use Ernestdefoe\Moments\Visibility;
use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\Foundation\Paths;
use Flarum\Http\UrlGenerator;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // The feed, the composer, the lightbox, the voice recorder and the
        // GIF picker are their own chunks, fetched when first needed.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        ->route('/moments', 'moments.index')
        ->route('/moments/{id:\d+}', 'moments.show', MomentDocument::class)
        ->route('/u/{username}/moments', 'user.moments'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\View())
        ->namespace('ernestdefoe-moments', __DIR__.'/views'),

    (new Extend\Routes('api'))
        ->get('/moments', 'ernestdefoe-moments.index', Api\ListMomentsController::class)
        ->post('/moments', 'ernestdefoe-moments.create', Api\CreateMomentController::class)
        ->get('/moments-count', 'ernestdefoe-moments.count', Api\NewCountController::class)
        ->get('/moments-config', 'ernestdefoe-moments.config', Api\ConfigController::class)
        ->get('/moments-topics', 'ernestdefoe-moments.topics', Api\TopicsController::class)
        ->get('/moments-gifs', 'ernestdefoe-moments.gifs', Api\GifsController::class)
        ->post('/moments-media', 'ernestdefoe-moments.media', Api\MediaController::class)
        ->get('/moments/{id:[0-9]+}', 'ernestdefoe-moments.show', Api\ShowMomentController::class)
        ->patch('/moments/{id:[0-9]+}', 'ernestdefoe-moments.update', Api\UpdateMomentController::class)
        ->delete('/moments/{id:[0-9]+}', 'ernestdefoe-moments.delete', Api\DeleteMomentController::class)
        ->get('/moments/{id:[0-9]+}/replies', 'ernestdefoe-moments.replies', Api\RepliesController::class)
        ->post('/moments/{id:[0-9]+}/reaction', 'ernestdefoe-moments.react', Api\ReactionController::class)
        ->delete('/moments/{id:[0-9]+}/reaction', 'ernestdefoe-moments.unreact', Api\ReactionController::class)
        ->post('/moments/{id:[0-9]+}/bookmark', 'ernestdefoe-moments.bookmark', Api\BookmarkController::class)
        ->delete('/moments/{id:[0-9]+}/bookmark', 'ernestdefoe-moments.unbookmark', Api\BookmarkController::class)
        ->post('/moments/{id:[0-9]+}/reports', 'ernestdefoe-moments.report', Api\ReportController::class)
        ->delete('/moments/{id:[0-9]+}/reports', 'ernestdefoe-moments.dismiss', Api\ReportController::class),

    (new Extend\ThrottleApi())
        ->set('ernestdefoe-moments', Throttle::class),

    (new Extend\Filesystem())
        ->disk(Storage::DISK, fn (Paths $paths, UrlGenerator $url) => [
            'root' => "$paths->public/assets/moments",
            'url' => $url->to('forum')->path('assets/moments'),
            'visibility' => 'public',
        ]),

    // The privacy rule as the model's visibility, so core's notification list
    // drops notifications about moments their reader can no longer see.
    (new Extend\ModelVisibility(Entry::class))
        ->scope(Visibility::class),

    new Extend\ApiResource(Api\Resource\MomentResource::class),

    (new Extend\Notification())
        ->type(Notification\MomentMentionedBlueprint::class, ['alert', 'email'])
        ->type(Notification\MomentRepliedBlueprint::class, ['alert', 'email'])
        ->type(Notification\MomentRepostedBlueprint::class, ['alert'])
        ->type(Notification\MomentReactedBlueprint::class, ['alert']),

    (new Extend\Settings())
        ->default('ernestdefoe-moments.show_nav', true)
        ->default('ernestdefoe-moments.char_limit', 500)
        ->default('ernestdefoe-moments.max_media', 4)
        ->default('ernestdefoe-moments.image_max_kb', 8192)
        ->default('ernestdefoe-moments.video_max_mb', 50)
        ->default('ernestdefoe-moments.voice_max_seconds', 120)
        ->default('ernestdefoe-moments.default_privacy', 'public')
        ->default('ernestdefoe-moments.edit_window', 60)
        ->default('ernestdefoe-moments.flood_seconds', 10)
        ->default('ernestdefoe-moments.giphy_rating', 'pg-13')
        ->serializeToForum('momentsShowNav', 'ernestdefoe-moments.show_nav', 'boolval'),

    // A permission the actor's groups were already loaded for: no query.
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('canViewMoments')
                ->get(fn ($forum, Context $context) => Access::canView($context->getActor())),
        ]),

    (new Extend\Console())
        ->command(PruneCommand::class)
        ->schedule(PruneCommand::class, function (\Illuminate\Console\Scheduling\Event $event): void {
            $event->daily();
        }),

    (new Extend\Conditional())
        ->whenExtensionEnabled('fof-seo', fn () => [
            (new \FoF\Seo\Extend\SEO())->addExtender('ernestdefoe-moments', MomentPage::class),
        ]),
];
