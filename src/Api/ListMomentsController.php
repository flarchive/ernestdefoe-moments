<?php

namespace Ernestdefoe\Moments\Api;

use Ernestdefoe\Moments\Access;
use Ernestdefoe\Moments\Entry;
use Ernestdefoe\Moments\Feed;
use Ernestdefoe\Moments\Presenter;
use Ernestdefoe\Moments\Visibility;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/moments — a feed, newest first.
 *
 * filter[feed] = everyone | following | bookmarks | topic | user | mine | reported
 * filter[topic], filter[user], filter[q]; page[before] = the last id seen;
 * page[limit] up to 50 (default 20).
 */
class ListMomentsController extends Controller
{
    public function __construct(Visibility $visibility, Presenter $presenter, Access $access, protected Feed $feed)
    {
        parent::__construct($visibility, $presenter, $access);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $params = $request->getQueryParams();
        $filter = (array) Arr::get($params, 'filter', []);
        $limit = max(1, min(50, (int) Arr::get($params, 'page.limit', 20)));
        $before = (int) Arr::get($params, 'page.before', 0);

        $query = $this->feed->query($actor, $filter);
        if ($query === null) {
            return new JsonResponse(['data' => [], 'included' => [], 'meta' => ['next' => null]]);
        }

        if ($before > 0) {
            $query->where('moments_entries.id', '<', $before);
        }

        $entries = $query->with($this->presenter->with($actor))->limit($limit + 1)->get();
        $more = $entries->count() > $limit;
        $entries = $entries->take($limit)->values();

        $roots = [];
        if (($filter['feed'] ?? null) === 'reported') {
            $rootIds = $entries->whereNotNull('root_id')->pluck('root_id')->unique()->all();
            $roots = $rootIds ? $this->visibility->entries($actor)->whereIn('id', $rootIds)->get()->keyBy('id')->all() : [];
        }

        $data = $this->presenter->entries($entries, $actor, $request, $roots);

        return new JsonResponse([
            'data' => $data,
            'included' => $this->presenter->includedUsers(),
            'meta' => [
                'next' => $more ? $entries->last()?->id : null,
                'newest' => $entries->first()?->id,
            ],
        ]);
    }
}
