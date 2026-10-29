<?php

namespace Ernestdefoe\Moments\Api;

use Ernestdefoe\Moments\Access;
use Ernestdefoe\Moments\Presenter;
use Ernestdefoe\Moments\Visibility;
use Ernestdefoe\Moments\Writer;
use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** PATCH /api/moments/{id} — content, privacy, isLocked, isHidden. */
class UpdateMomentController extends Controller
{
    public function __construct(Visibility $visibility, Presenter $presenter, Access $access, protected Writer $writer)
    {
        parent::__construct($visibility, $presenter, $access);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->member($request);
        $entry = $this->visible($actor, $this->id($request));
        $body = Arr::only($this->body($request), ['content', 'privacy', 'isLocked', 'isHidden']);

        $this->writer->update($actor, $entry, $body);

        return $this->present($entry->fresh() ?? $entry, $actor, $request);
    }
}
