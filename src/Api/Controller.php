<?php

namespace Ernestdefoe\Moments\Api;

use Ernestdefoe\Moments\Access;
use Ernestdefoe\Moments\Entry;
use Ernestdefoe\Moments\Presenter;
use Ernestdefoe\Moments\Visibility;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\NotAuthenticatedException;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

abstract class Controller implements RequestHandlerInterface
{
    public function __construct(protected Visibility $visibility, protected Presenter $presenter, protected Access $access)
    {
    }

    protected function actor(ServerRequestInterface $request): User
    {
        $actor = RequestUtil::getActor($request);
        if (! Access::canView($actor)) {
            throw new PermissionDeniedException();
        }

        return $actor;
    }

    protected function member(ServerRequestInterface $request): User
    {
        $actor = RequestUtil::getActor($request);
        if ($actor->isGuest()) {
            throw new NotAuthenticatedException();
        }

        return $this->actor($request);
    }

    protected function id(ServerRequestInterface $request): int
    {
        $id = (string) Arr::get($request->getQueryParams(), 'id', '');

        return ctype_digit($id) && strlen($id) <= 10 ? (int) $id : 0;
    }

    /** 🚨 Every lookup by id goes through the privacy rule; anything else is a 404. */
    protected function visible(User $actor, int $id, array $with = []): Entry
    {
        $entry = $id > 0 ? $this->visibility->entries($actor)->with($with)->find($id) : null;
        if (! $entry) {
            throw new ModelNotFoundException();
        }

        return $entry;
    }

    /** @param array<string, mixed> $meta */
    protected function present(Entry $entry, User $actor, ServerRequestInterface $request, array $meta = []): ResponseInterface
    {
        $entry->loadMissing($this->presenter->with($actor));
        $roots = [];
        if (! $entry->isRoot() && ($root = $this->visibility->entries($actor)->find($entry->root_id))) {
            $roots[$root->id] = $root;
        }
        $data = $this->presenter->entries(collect([$entry]), $actor, $request, $roots)[0];

        return new JsonResponse(['data' => $data, 'included' => $this->presenter->includedUsers(), 'meta' => (object) $meta]);
    }

    protected function body(ServerRequestInterface $request): array
    {
        return (array) $request->getParsedBody();
    }
}
