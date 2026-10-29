<?php

namespace Ernestdefoe\Moments\Api;

use Ernestdefoe\Moments\Access;
use Ernestdefoe\Moments\Media\FofBridge;
use Ernestdefoe\Moments\Media\Giphy;
use Ernestdefoe\Moments\Media\Uploader;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\Exception\NotAuthenticatedException;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/moments-media — something to attach to a moment you're writing:
 *  - multipart `file` + `purpose=media`: a photo or video (Moments' own
 *    upload path, when fof/upload is not enabled);
 *  - multipart `file` + `purpose=voice` (+ `duration` ms): a voice note;
 *  - JSON `{fofFileId}`: a file you just uploaded through fof/upload;
 *  - JSON `{gif: {url, width, height}}`: a GIPHY GIF.
 * Each is gated by its own permission. The row waits, unattached, until the
 * moment is posted; unattached rows are pruned after a day.
 */
class MediaController implements RequestHandlerInterface
{
    public function __construct(
        protected Uploader $uploader,
        protected FofBridge $fof,
        protected Giphy $giphy,
        protected TranslatorInterface $translator
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        if ($actor->isGuest()) {
            throw new NotAuthenticatedException();
        }
        if (! Access::canView($actor) || ! (Access::canPost($actor) || Access::canReplyAtAll($actor))) {
            throw new PermissionDeniedException();
        }

        $body = (array) $request->getParsedBody();
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;

        if ($file instanceof UploadedFileInterface) {
            $purpose = Arr::get($body, 'purpose') === 'voice' ? 'voice' : 'media';
            if ($purpose === 'voice' ? ! Access::canRecordVoice($actor) : ! Access::canUploadMedia($actor)) {
                throw new PermissionDeniedException();
            }
            if ($purpose === 'media' && $this->fof->enabled()) {
                // With fof/upload enabled, photos and video go through it.
                throw new PermissionDeniedException();
            }
            $duration = Arr::get($body, 'duration');
            $media = $this->uploader->upload($actor, $file, $purpose, is_numeric($duration) ? (int) $duration : null);
        } elseif (isset($body['fofFileId'])) {
            if (! Access::canUploadMedia($actor) || ! $this->fof->enabled()) {
                throw new PermissionDeniedException();
            }
            $media = $this->fof->attach($actor, (int) $body['fofFileId']);
        } elseif (is_array($body['gif'] ?? null)) {
            if (! Access::canUseGifs($actor) || ! $this->giphy->enabled()) {
                throw new PermissionDeniedException();
            }
            $media = $this->giphy->attach($actor, $body['gif']);
        } else {
            throw new ValidationException(['file' => $this->translator->trans('ernestdefoe-moments.api.upload_failed')]);
        }

        return new JsonResponse(['data' => $media->present()], 201);
    }
}
