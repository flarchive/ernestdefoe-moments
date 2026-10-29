<?php

namespace Ernestdefoe\Moments\Media;

/**
 * What an uploaded file really is, from its first bytes — never from its
 * name or the type the browser claimed. Only the formats listed here are
 * accepted at all: no SVG, no HTML, nothing a browser would run.
 */
class Sniffer
{
    public const IMAGES = ['jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];

    /** @return array{kind: string, mime: string, ext: string}|null */
    public static function sniff(string $head): ?array
    {
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return ['kind' => 'image', 'mime' => 'image/jpeg', 'ext' => 'jpg'];
        }
        if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
            return ['kind' => 'image', 'mime' => 'image/png', 'ext' => 'png'];
        }
        if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) {
            return ['kind' => 'image', 'mime' => 'image/gif', 'ext' => 'gif'];
        }
        if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            return ['kind' => 'image', 'mime' => 'image/webp', 'ext' => 'webp'];
        }

        // ISO base media (MP4 / M4A / MOV): a box size, then "ftyp" and a brand.
        if (substr($head, 4, 4) === 'ftyp') {
            $brand = substr($head, 8, 4);
            $audio = in_array($brand, ['M4A ', 'M4B ', 'F4A '], true);

            return in_array($brand, ['qt  '], true)
                ? null
                : ($audio
                    ? ['kind' => 'audio', 'mime' => 'audio/mp4', 'ext' => 'm4a']
                    : ['kind' => 'video', 'mime' => 'video/mp4', 'ext' => 'mp4']);
        }

        // Matroska / WebM: the EBML magic, and a "webm" DocType.
        if (str_starts_with($head, "\x1A\x45\xDF\xA3")) {
            return str_contains(substr($head, 0, 64), 'webm')
                ? ['kind' => 'video', 'mime' => 'video/webm', 'ext' => 'webm']
                : null;
        }

        if (str_starts_with($head, 'OggS')) {
            return ['kind' => 'audio', 'mime' => 'audio/ogg', 'ext' => 'ogg'];
        }

        return null;
    }

    /**
     * The duration an MP4/M4A declares in its movie header, in
     * milliseconds, or null when it can't be read (WebM from a recorder
     * declares none).
     */
    public static function mp4Duration(string $bytes): ?int
    {
        $pos = strpos($bytes, 'mvhd');
        if ($pos === false || $pos + 32 > strlen($bytes)) {
            return null;
        }

        $version = ord($bytes[$pos + 4]);
        if ($version === 1) {
            $u = unpack('Nscale/Jdur', substr($bytes, $pos + 24, 12));
        } else {
            $u = unpack('Nscale/Ndur', substr($bytes, $pos + 16, 8));
        }

        if (! $u || $u['scale'] < 1) {
            return null;
        }

        return (int) round($u['dur'] / $u['scale'] * 1000);
    }
}
