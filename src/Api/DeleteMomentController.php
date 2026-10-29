<?php

namespace Ernestdefoe\Moments\Api;

use Ernestdefoe\Moments\Access;
use Ernestdefoe\Moments\Presenter;
use Ernestdefoe\Moments\Visibility;
use Ernestdefoe\Moments\Writer;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** DELETE /api/moments/{id} — its author or a moderator; replies go with it. */
class DeleteMomentController extends Controller
{
    public function __construct(Visibility $visibility, Presenter $presenter, Access $access, protected Writer $writer)
    {
        parent::__construct($visibility, $presenter, $access);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->member($request);
        $this->writer->delete($actor, $this->visible($actor, $this->id($request)));

        return new EmptyResponse(204);
    }
}
