<?php

namespace Ernestdefoe\Moments;

use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The real-time nudge, through flarum/realtime when it is installed.
 *
 * 🚨 Nothing a moment says ever travels over the socket: a push carries an
 * id and nothing else, and each browser then asks the API for what it may
 * see, through the privacy rule. A public moment is announced on realtime's
 * public channel; a followers-only one only on its audience's private user
 * channels (flarum/realtime authorises no channels of an extension's own);
 * a private one only to its author's other tabs.
 *
 * Without realtime, or while its socket is down, browsers poll the cheap
 * new-moments count instead.
 */
class Broadcaster
{
    public function __construct(protected Container $container, protected Follows $follows, protected LoggerInterface $log)
    {
    }

    public function available(): bool
    {
        return class_exists(\Pusher\Pusher::class) && $this->container->bound(\Pusher\Pusher::class);
    }

    /**
     * Where a push about this entry may go.
     *
     * @return array{public: bool, users: list<int>}
     */
    public function audience(Entry $entry): array
    {
        if ($entry->hidden_at !== null) {
            return ['public' => false, 'users' => []];
        }

        return match ($entry->privacy) {
            Entry::PUBLIC => ['public' => true, 'users' => []],
            Entry::FOLLOWERS => ['public' => false, 'users' => array_values(array_unique(array_merge([$entry->audience_user_id], $this->follows->followersOf($entry->audience_user_id))))],
            default => ['public' => false, 'users' => [$entry->audience_user_id]],
        };
    }

    public function created(Entry $entry): void
    {
        if (! $this->available()) {
            return;
        }

        $event = $entry->isRoot() ? 'moments.new' : 'moments.reply';
        $payload = $entry->isRoot() ? ['id' => $entry->id] : ['id' => $entry->id, 'rootId' => $entry->root_id];
        $audience = $this->audience($entry);

        // The moment is already saved: a daemon that is down must not turn a
        // post into an error. Browsers reconcile on their next poll.
        try {
            /** @var \Pusher\Pusher $pusher */
            $pusher = $this->container->make(\Pusher\Pusher::class);

            if ($audience['public']) {
                $pusher->trigger('public', $event, $payload);
            }

            foreach (array_chunk($audience['users'], 100) as $chunk) {
                $pusher->trigger(array_map(fn (int $id) => 'private-user='.$id, $chunk), $event, $payload);
            }
        } catch (Throwable $e) {
            $this->log->warning('[moments] realtime push failed: '.$e->getMessage());
        }
    }
}
