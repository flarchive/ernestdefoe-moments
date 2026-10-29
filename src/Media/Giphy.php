<?php

namespace Ernestdefoe\Moments\Media;

use Carbon\Carbon;
use Ernestdefoe\Moments\Media;
use Ernestdefoe\Moments\Settings;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use GuzzleHttp\Client;
use Illuminate\Contracts\Cache\Repository;
use Psr\Log\LoggerInterface;

/**
 * GIPHY search, proxied: the admin's API key stays on the server, results are
 * cached for ten minutes per query, and only a GIF's own address on GIPHY's
 * media hosts is ever stored — checked against an allow-list, not trusted
 * from the browser.
 */
class Giphy
{
    public const URL_PATTERN = '#^https://(?:media[0-9]?|i)\.giphy\.com/media/(?:v1\.[A-Za-z0-9_.=-]+/)?([A-Za-z0-9]{1,64})/[A-Za-z0-9_.-]{1,64}\.(?:gif|webp|mp4)(?:\?[A-Za-z0-9_.=&-]{0,200})?$#';

    public function __construct(
        protected Settings $settings,
        protected Repository $cache,
        protected TranslatorInterface $translator,
        protected LoggerInterface $log
    ) {
    }

    public function enabled(): bool
    {
        return $this->settings->giphyKey() !== '';
    }

    /** @return list<array<string, mixed>> */
    public function search(string $q, int $offset = 0): array
    {
        $q = mb_substr(trim($q), 0, 50);
        $offset = max(0, min(200, $offset));
        $key = 'ernestdefoe-moments.giphy.'.md5($this->settings->giphyRating().'|'.mb_strtolower($q).'|'.$offset);

        return $this->cache->remember($key, 600, function () use ($q, $offset) {
            $endpoint = $q === '' ? 'trending' : 'search';
            $query = ['api_key' => $this->settings->giphyKey(), 'limit' => 24, 'offset' => $offset, 'rating' => $this->settings->giphyRating()];
            if ($q !== '') {
                $query['q'] = $q;
            }

            try {
                $response = (new Client(['timeout' => 6]))->get('https://api.giphy.com/v1/gifs/'.$endpoint, ['query' => $query]);
                $json = json_decode((string) $response->getBody(), true);
            } catch (\Throwable $e) {
                $this->log->warning('[moments] GIPHY search failed: '.$e->getMessage());

                return [];
            }

            $out = [];
            foreach ((array) ($json['data'] ?? []) as $gif) {
                $full = $gif['images']['downsized_medium'] ?? $gif['images']['original'] ?? null;
                $preview = $gif['images']['fixed_width_small'] ?? $gif['images']['fixed_width'] ?? $full;
                if (! $full || ! $preview || ! self::allowed((string) ($full['url'] ?? '')) || ! self::allowed((string) ($preview['url'] ?? ''))) {
                    continue;
                }
                $out[] = [
                    'id' => (string) $gif['id'],
                    'title' => mb_substr((string) ($gif['title'] ?? ''), 0, 120),
                    'url' => (string) $full['url'],
                    'preview' => (string) $preview['url'],
                    'width' => (int) ($full['width'] ?? 0),
                    'height' => (int) ($full['height'] ?? 0),
                ];
            }

            return $out;
        });
    }

    public static function allowed(string $url): bool
    {
        return (bool) preg_match(self::URL_PATTERN, $url);
    }

    /** @param array<string, mixed> $gif */
    public function attach(User $actor, array $gif): Media
    {
        $url = (string) preg_replace('/[\x00-\x1F\x7F\s]/', '', (string) ($gif['url'] ?? ''));

        if (! self::allowed($url)) {
            throw new ValidationException(['gif' => $this->translator->trans('ernestdefoe-moments.api.bad_gif')]);
        }

        $media = new Media();
        $media->user_id = $actor->id;
        $media->source = 'giphy';
        $media->kind = 'gif';
        $media->mime = str_ends_with(parse_url($url, PHP_URL_PATH) ?: '', '.mp4') ? 'video/mp4' : 'image/gif';
        $media->url = $url;
        $media->width = max(0, min(4000, (int) ($gif['width'] ?? 0))) ?: null;
        $media->height = max(0, min(4000, (int) ($gif['height'] ?? 0))) ?: null;
        $media->created_at = Carbon::now();
        $media->save();

        return $media;
    }
}
