<?php

namespace Ernestdefoe\Moments\Api;

use Ernestdefoe\Moments\Access;
use Ernestdefoe\Moments\Topic;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/moments-topics?sort=popular|active&limit=10 — topics with at least
 * one public, visible moment (counts never include anything else).
 */
class TopicsController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (! Access::canView(RequestUtil::getActor($request))) {
            return new JsonResponse(['data' => []]);
        }

        $params = $request->getQueryParams();
        $limit = max(1, min(30, (int) Arr::get($params, 'limit', 10)));
        $query = Topic::query()->where('moment_count', '>', 0);

        Arr::get($params, 'sort') === 'active'
            ? $query->orderByDesc('last_used_at')->orderByDesc('moment_count')
            : $query->orderByDesc('moment_count')->orderByDesc('last_used_at');

        return new JsonResponse(['data' => $query->limit($limit)->get()->map(fn (Topic $t) => [
            'name' => $t->name,
            'key' => $t->name_key,
            'count' => $t->moment_count,
        ])->values()->all()]);
    }
}
