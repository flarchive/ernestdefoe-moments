<?php

namespace Ernestdefoe\Moments;

use Flarum\Settings\SettingsRepositoryInterface;

/** Moments' settings, read with their bounds applied. */
class Settings
{
    public const PREFIX = 'ernestdefoe-moments.';

    public const DEFAULTS = [
        'show_nav' => true,
        'char_limit' => 500,
        'max_media' => 4,
        'image_max_kb' => 8192,
        'video_max_mb' => 50,
        'voice_max_seconds' => 120,
        'giphy_key' => '',
        'giphy_rating' => 'pg-13',
        'default_privacy' => 'public',
        'edit_window' => 60,
        'flood_seconds' => 10,
    ];

    public function __construct(protected SettingsRepositoryInterface $settings)
    {
    }

    public function raw(string $key): mixed
    {
        return $this->settings->get(self::PREFIX.$key, self::DEFAULTS[$key] ?? null);
    }

    protected function int(string $key, int $min, int $max): int
    {
        $value = $this->raw($key);

        return max($min, min($max, is_numeric($value) ? (int) $value : (int) self::DEFAULTS[$key]));
    }

    public function charLimit(): int
    {
        return $this->int('char_limit', 20, 5000);
    }

    public function maxMedia(): int
    {
        return $this->int('max_media', 1, 10);
    }

    public function imageMaxBytes(): int
    {
        return $this->int('image_max_kb', 100, 51200) * 1024;
    }

    public function videoMaxBytes(): int
    {
        return $this->int('video_max_mb', 1, 1024) * 1024 * 1024;
    }

    public function voiceMaxSeconds(): int
    {
        return $this->int('voice_max_seconds', 5, 900);
    }

    /**
     * A voice note can't be measured on the server for every container (a
     * recording from Chrome carries no duration), so its size is capped at
     * what the longest allowed note could reach at a generous voice bitrate.
     */
    public function voiceMaxBytes(): int
    {
        return $this->voiceMaxSeconds() * 24 * 1024 + 64 * 1024;
    }

    /** Minutes an author may edit for; 0 means always. */
    public function editWindow(): int
    {
        return $this->int('edit_window', 0, 525600);
    }

    public function floodSeconds(): int
    {
        return $this->int('flood_seconds', 0, 3600);
    }

    public function giphyKey(): string
    {
        return trim((string) $this->raw('giphy_key'));
    }

    public function giphyRating(): string
    {
        $rating = (string) $this->raw('giphy_rating');

        return in_array($rating, ['g', 'pg', 'pg-13', 'r'], true) ? $rating : 'pg-13';
    }

    public function defaultPrivacy(): string
    {
        $privacy = (string) $this->raw('default_privacy');

        return in_array($privacy, Entry::PRIVACIES, true) ? $privacy : Entry::PUBLIC;
    }

    public function showNav(): bool
    {
        return (bool) $this->raw('show_nav');
    }
}
