<?php

namespace Ernestdefoe\Moments\Notification;

use Ernestdefoe\Moments\Entry;
use Flarum\Database\AbstractModel;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\User\User;

/**
 * A notification about a moment. Its subject is always a moment the
 * recipient can see (their own, or one they were mentioned or replied to in),
 * and core's notification list drops it the moment that stops being true:
 * Entry's visibility scoper is the privacy rule.
 */
abstract class MomentBlueprint implements BlueprintInterface, AlertableInterface, MailableInterface
{
    /** @param array<string, mixed>|null $data */
    public function __construct(public Entry $entry, public User $from, public ?array $data = null)
    {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->entry;
    }

    public function getFromUser(): ?User
    {
        return $this->from;
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    public static function getSubjectModel(): string
    {
        return Entry::class;
    }

    public function getEmailViews(): array
    {
        return [
            'text' => 'ernestdefoe-moments::emails.plain.notification',
            'html' => 'ernestdefoe-moments::emails.html.notification',
        ];
    }

    /** The translation key under ernestdefoe-moments.email. */
    abstract public function emailKey(): string;

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('ernestdefoe-moments.email.'.$this->emailKey().'.subject', [
            '{actor}' => $this->from->display_name,
        ]);
    }
}
