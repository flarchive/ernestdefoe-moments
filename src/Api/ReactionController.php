<?php

namespace Ernestdefoe\Moments\Api;

use Ernestdefoe\Moments\Access;
use Ernestdefoe\Moments\Presenter;
use Ernestdefoe\Moments\Reactions;
use Ernestdefoe\Moments\Visibility;
use Ernestdefoe\Moments\Writer;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /api/moments/{id}/reaction {reaction}; DELETE takes yours back. */
class ReactionController extends Controller
{
    public function __construct(
        Visibility $visibility,
        Presenter $presenter,
        Access $access,
        protected Writer $writer,
        protected Reactions $reactions,
        protected TranslatorInterface $translator
    ) {
        parent::__construct($visibility, $presenter, $access);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->member($request);
        $entry = $this->visible($actor, $this->id($request));

        if ($entry->hidden_at !== null) {
            throw new PermissionDeniedException();
        }

        $reaction = null;
        if ($request->getMethod() !== 'DELETE') {
            $reaction = (string) ($this->body($request)['reaction'] ?? '');
            if (! $this->reactions->isAllowed($reaction)) {
                throw new ValidationException(['reaction' => $this->translator->trans('ernestdefoe-moments.api.bad_reaction')]);
            }
        }

        $this->writer->react($actor, $entry, $reaction);

        return $this->present($entry, $actor, $request);
    }
}
