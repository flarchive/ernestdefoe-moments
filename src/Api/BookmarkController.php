<?php

namespace Ernestdefoe\Moments\Api;

use Ernestdefoe\Moments\Access;
use Ernestdefoe\Moments\Presenter;
use Ernestdefoe\Moments\Visibility;
use Ernestdefoe\Moments\Writer;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/moments/{id}/bookmark, DELETE to remove. Bookmarks are the
 * member's alone: a bookmark is only ever written for the actor, only on a
 * moment the actor can see.
 */
class BookmarkController extends Controller
{
    public function __construct(Visibility $visibility, Presenter $presenter, Access $access, protected Writer $writer)
    {
        parent::__construct($visibility, $presenter, $access);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->member($request);
        $entry = $this->visible($actor, $this->id($request));
        $on = $request->getMethod() !== 'DELETE';

        $this->writer->bookmark($actor, $entry, $on);

        return new JsonResponse(['bookmarked' => $on]);
    }
}
