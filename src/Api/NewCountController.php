<?php

namespace Ernestdefoe\Moments\Api;

use Ernestdefoe\Moments\Access;
use Ernestdefoe\Moments\Feed;
use Ernestdefoe\Moments\Presenter;
use Ernestdefoe\Moments\Visibility;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/moments-count?after=ID&filter[...] — how many moments newer than
 * the newest one on screen this reader may see. The cheap poll behind
 * "3 new moments — show" when there is no live socket: one indexed COUNT,
 * capped, nothing rendered.
 */
class NewCountController extends Controller
{
    public const CAP = 99;

    public function __construct(Visibility $visibility, Presenter $presenter, Access $access, protected Feed $feed)
    {
        parent::__construct($visibility, $presenter, $access);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $params = $request->getQueryParams();
        $after = max(0, (int) Arr::get($params, 'after', 0));
        $query = $this->feed->query($actor, (array) Arr::get($params, 'filter', []));

        $count = $query === null ? 0 : count($query->reorder()->where('moments_entries.id', '>', $after)
            ->where('moments_entries.user_id', '!=', (int) $actor->id)
            ->limit(self::CAP + 1)->pluck('moments_entries.id')->all());

        return new JsonResponse(['count' => min(self::CAP, $count)]);
    }
}
