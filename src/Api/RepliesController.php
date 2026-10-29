<?php

namespace Ernestdefoe\Moments\Api;

use Ernestdefoe\Moments\Entry;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/moments/{id}/replies?sort=recent|top&page[after]=CURSOR
 *
 * Twenty top-level replies at a time with every reply beneath them, each
 * through the privacy rule (a reply carries its moment's privacy, and the
 * moment itself is checked first). `recent` is newest first; `top` is most
 * reacted first. Nested replies under a thread read oldest first, like a
 * conversation.
 */
class RepliesController extends Controller
{
    public const PAGE = 20;
    public const MAX_NESTED = 500;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $root = $this->visible($actor, $this->id($request));
        if (! $root->isRoot()) {
            $root = $this->visible($actor, (int) $root->root_id);
        }

        $params = $request->getQueryParams();
        $sort = Arr::get($params, 'sort') === 'top' ? 'top' : 'recent';
        $cursor = (string) Arr::get($params, 'page.after', '');

        $top = $this->visibility->entries($actor)->where('moments_entries.parent_id', $root->id);

        if ($sort === 'top') {
            if (preg_match('/^(\d{1,10}):(\d{1,10})$/', $cursor, $m)) {
                [$count, $id] = [(int) $m[1], (int) $m[2]];
                $top->where(fn ($q) => $q->where('reaction_count', '<', $count)
                    ->orWhere(fn ($q) => $q->where('reaction_count', $count)->where('moments_entries.id', '<', $id)));
            }
            $top->orderByDesc('reaction_count')->orderByDesc('moments_entries.id');
        } else {
            if (ctype_digit($cursor) && strlen($cursor) <= 10) {
                $top->where('moments_entries.id', '<', (int) $cursor);
            }
            $top->orderByDesc('moments_entries.id');
        }

        $heads = $top->limit(self::PAGE + 1)->get();
        $more = $heads->count() > self::PAGE;
        $heads = $heads->take(self::PAGE)->values();

        $nested = $heads->isEmpty() ? collect() : $this->visibility->entries($actor)
            ->whereIn('moments_entries.thread_id', $heads->pluck('id')->all())
            ->where('moments_entries.parent_id', '!=', $root->id)
            ->orderBy('moments_entries.id')->limit(self::MAX_NESTED)->get();

        $last = $heads->last();
        $next = ! $more || ! $last ? null : ($sort === 'top' ? $last->reaction_count.':'.$last->id : (string) $last->id);

        // One eager load for heads and nested together: the same queries however the thread is shaped.
        $all = $heads->concat($nested)->values();
        $all->load($this->presenter->with($actor));

        $data = $this->presenter->entries($all, $actor, $request, [$root->id => $root]);

        return new JsonResponse([
            'data' => $data,
            'included' => $this->presenter->includedUsers(),
            'meta' => ['next' => $next, 'sort' => $sort],
        ]);
    }
}
