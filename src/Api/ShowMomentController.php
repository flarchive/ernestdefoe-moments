<?php

namespace Ernestdefoe\Moments\Api;

use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/moments/{id} — one moment. A reply's id gives its moment, with
 * `meta.focus` naming the reply, so a link to a reply opens the whole
 * thread. `?source=1` adds what the editor is filled with, for its author.
 */
class ShowMomentController extends Controller
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $entry = $this->visible($actor, $this->id($request));
        $meta = [];

        if (Arr::get($request->getQueryParams(), 'source')) {
            if (! $this->access->canEdit($actor, $entry)) {
                return new JsonResponse(['errors' => [['status' => '403', 'code' => 'permission_denied']]], 403);
            }

            return new JsonResponse(['data' => $this->presenter->editable($entry)]);
        }

        if (! $entry->isRoot()) {
            $meta['focus'] = $entry->id;
            $entry = $this->visible($actor, (int) $entry->root_id);
        }

        return $this->present($entry, $actor, $request, $meta);
    }
}
