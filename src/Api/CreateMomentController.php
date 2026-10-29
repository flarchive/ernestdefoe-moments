<?php

namespace Ernestdefoe\Moments\Api;

use Ernestdefoe\Moments\Access;
use Ernestdefoe\Moments\Presenter;
use Ernestdefoe\Moments\Visibility;
use Ernestdefoe\Moments\Writer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /api/moments — a moment, a reply (parentId) or a repost (repostOf). */
class CreateMomentController extends Controller
{
    public function __construct(Visibility $visibility, Presenter $presenter, Access $access, protected Writer $writer)
    {
        parent::__construct($visibility, $presenter, $access);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->member($request);
        $entry = $this->writer->create($actor, $this->body($request));

        return $this->present($entry->fresh() ?? $entry, $actor, $request)->withStatus(201);
    }
}
