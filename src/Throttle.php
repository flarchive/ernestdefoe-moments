<?php

namespace Ernestdefoe\Moments;

use Carbon\Carbon;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Flood control, the way Flarum's own posting is throttled:
 *  - a moment or reply every `flood_seconds` (default 10);
 *  - 30 uploads in ten minutes;
 *  - 30 GIF searches a minute.
 * A throttled request gets Flarum's own 429 "slow down".
 * 🚨 Never returns false: that would switch off every other throttler.
 */
class Throttle
{
    public function __construct(protected Settings $settings, protected ConnectionInterface $db, protected Repository $cache)
    {
    }

    public function __invoke(ServerRequestInterface $request): ?bool
    {
        $route = (string) $request->getAttribute('routeName');

        if (! str_starts_with($route, 'ernestdefoe-moments.')) {
            return null;
        }

        $actor = RequestUtil::getActor($request);
        if ($actor->isGuest() || $actor->can('postWithoutThrottle')) {
            return null;
        }

        switch ($route) {
            case 'ernestdefoe-moments.create':
                $seconds = $this->settings->floodSeconds();

                return $seconds > 0 && $this->db->table('moments_entries')->where('user_id', $actor->id)
                    ->where('created_at', '>=', Carbon::now()->subSeconds($seconds))->exists() ? true : null;

            case 'ernestdefoe-moments.media':
                return $this->db->table('moments_media')->where('user_id', $actor->id)
                    ->where('created_at', '>=', Carbon::now()->subMinutes(10))->count() >= 30 ? true : null;

            case 'ernestdefoe-moments.gifs':
                $key = 'ernestdefoe-moments.gif-rate.'.$actor->id.'.'.intdiv(time(), 60);
                $count = (int) $this->cache->get($key, 0) + 1;
                $this->cache->put($key, $count, 120);

                return $count > 30 ? true : null;
        }

        return null;
    }
}
