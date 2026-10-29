<?php

namespace Ernestdefoe\Moments\Api;

use Ernestdefoe\Moments\Access;
use Ernestdefoe\Moments\Presenter;
use Ernestdefoe\Moments\Visibility;
use Ernestdefoe\Moments\Writer;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /api/moments/{id}/reports {reason, detail}; DELETE dismisses them (moderators). */
class ReportController extends Controller
{
    public function __construct(Visibility $visibility, Presenter $presenter, Access $access, protected Writer $writer)
    {
        parent::__construct($visibility, $presenter, $access);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->member($request);
        $entry = $this->visible($actor, $this->id($request));

        if ($request->getMethod() === 'DELETE') {
            $this->writer->dismissReports($actor, $entry);
        } else {
            $body = $this->body($request);
            $this->writer->report($actor, $entry, (string) ($body['reason'] ?? ''), isset($body['detail']) ? (string) $body['detail'] : null);
        }

        return new EmptyResponse(204);
    }
}
