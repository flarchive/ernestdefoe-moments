<?php

namespace Ernestdefoe\Moments\Api;

use Ernestdefoe\Moments\Access;
use Ernestdefoe\Moments\Media\Giphy;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** GET /api/moments-gifs?q=&offset= — GIPHY search through the server. */
class GifsController implements RequestHandlerInterface
{
    public function __construct(protected Giphy $giphy)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        if (! Access::canUseGifs($actor) || ! Access::canView($actor) || ! $this->giphy->enabled()) {
            throw new PermissionDeniedException();
        }

        $params = $request->getQueryParams();

        return new JsonResponse(['data' => $this->giphy->search((string) Arr::get($params, 'q', ''), (int) Arr::get($params, 'offset', 0))]);
    }
}
