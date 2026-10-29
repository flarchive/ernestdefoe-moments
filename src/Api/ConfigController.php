<?php

namespace Ernestdefoe\Moments\Api;

use Ernestdefoe\Moments\Access;
use Ernestdefoe\Moments\Follows;
use Ernestdefoe\Moments\Media\FofBridge;
use Ernestdefoe\Moments\Media\Giphy;
use Ernestdefoe\Moments\Reactions;
use Ernestdefoe\Moments\Settings;
use Flarum\Extension\ExtensionManager;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/moments-config — what the composer and feed need, fetched with the
 * page rather than added to every page's payload. 🚨 Never the GIPHY key:
 * only whether GIFs are available.
 */
class ConfigController implements RequestHandlerInterface
{
    public function __construct(
        protected Settings $settings,
        protected Follows $follows,
        protected FofBridge $fof,
        protected Giphy $giphy,
        protected Reactions $reactions,
        protected ExtensionManager $extensions
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);

        return new JsonResponse(['data' => [
            'canPost' => Access::canPost($actor),
            'canReply' => Access::canReplyAtAll($actor),
            'canUploadMedia' => Access::canUploadMedia($actor),
            'canRecordVoice' => Access::canRecordVoice($actor),
            'canUseGifs' => Access::canUseGifs($actor) && $this->giphy->enabled(),
            'canModerate' => Access::canModerate($actor),
            'charLimit' => $this->settings->charLimit(),
            'maxMedia' => $this->settings->maxMedia(),
            'imageMaxBytes' => $this->settings->imageMaxBytes(),
            'videoMaxBytes' => $this->settings->videoMaxBytes(),
            'voiceMaxSeconds' => $this->settings->voiceMaxSeconds(),
            'defaultPrivacy' => $this->settings->defaultPrivacy() === 'followers' && ! $this->follows->enabled() ? 'public' : $this->settings->defaultPrivacy(),
            'following' => $this->follows->enabled(),
            'fofUpload' => $this->fof->enabled(),
            'hashtags' => $this->extensions->isEnabled('ernestdefoe-hashtags'),
            'reactions' => $this->reactions->allowed(),
            'fofReactions' => $this->reactions->usesFof(),
            'realtime' => $this->extensions->isEnabled('flarum-realtime'),
        ]]);
    }
}
