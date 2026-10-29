<?php

namespace Ernestdefoe\Moments\Media;

use Carbon\Carbon;
use Ernestdefoe\Moments\Media;
use Ernestdefoe\Moments\Settings;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Intervention\Image\Encoders\GifEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Moments' own upload path, used when fof/upload is not enabled (and always
 * for voice notes).
 *
 *  - The type is sniffed from the bytes (Sniffer); the name and the claimed
 *    type are ignored.
 *  - Images are decoded and RE-ENCODED with Intervention Image (WebP; an
 *    animated GIF stays a GIF), which drops EXIF/GPS and anything smuggled
 *    after the image data, and scaled down to 2048px.
 *  - Video is MP4 or WebM only, voice WebM/Ogg/MP4 audio, both size-capped.
 *  - Stored under a random name with the extension of what it really is.
 */
class Uploader
{
    public const MAX_SIDE = 2048;

    public function __construct(
        protected Storage $storage,
        protected Settings $settings,
        protected ImageManager $images,
        protected TranslatorInterface $translator
    ) {
    }

    protected function fail(string $key, array $params = []): never
    {
        throw new ValidationException(['file' => $this->translator->trans('ernestdefoe-moments.api.'.$key, $params)]);
    }

    public function upload(User $actor, UploadedFileInterface $file, string $purpose, ?int $durationMs = null): Media
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            $this->fail('upload_failed');
        }

        $size = (int) $file->getSize();
        $max = $purpose === 'voice' ? $this->settings->voiceMaxBytes() : max($this->settings->imageMaxBytes(), $this->settings->videoMaxBytes());
        if ($size < 1 || $size > $max) {
            $this->fail('too_large');
        }

        $stream = $file->getStream();
        $stream->rewind();
        $bytes = $stream->getContents();
        $type = Sniffer::sniff(substr($bytes, 0, 64));

        if ($type === null) {
            $this->fail('bad_type');
        }

        $media = new Media();
        $media->user_id = $actor->id;
        $media->source = 'local';
        $media->created_at = Carbon::now();

        if ($purpose === 'voice') {
            if (! in_array($type['ext'], ['webm', 'ogg', 'mp4', 'm4a'], true)) {
                $this->fail('bad_type');
            }
            $declared = in_array($type['ext'], ['mp4', 'm4a'], true) ? Sniffer::mp4Duration($bytes) : null;
            $duration = $declared ?? $durationMs;
            $cap = $this->settings->voiceMaxSeconds() * 1000 + 1500;
            if ($duration !== null && $duration > $cap) {
                $this->fail('voice_too_long', ['{max}' => $this->settings->voiceMaxSeconds()]);
            }
            [$mime, $ext] = match ($type['ext']) {
                'webm' => ['audio/webm', 'weba'],
                'ogg' => ['audio/ogg', 'ogg'],
                default => ['audio/mp4', 'm4a'],
            };
            $stored = $this->storage->put($bytes, $ext);
            $media->kind = 'audio';
            $media->mime = $mime;
            $media->duration = $duration !== null ? max(0, min($duration, $cap)) : null;
        } elseif ($type['kind'] === 'image') {
            if ($size > $this->settings->imageMaxBytes()) {
                $this->fail('too_large');
            }
            [$stored, $width, $height, $mime] = $this->image($bytes, $type['ext'] === 'gif');
            $media->kind = 'image';
            $media->mime = $mime;
            $media->width = $width;
            $media->height = $height;
        } elseif ($type['kind'] === 'video') {
            if ($size > $this->settings->videoMaxBytes()) {
                $this->fail('too_large');
            }
            $stored = $this->storage->put($bytes, $type['ext']);
            $media->kind = 'video';
            $media->mime = $type['mime'];
            $media->duration = $type['ext'] === 'mp4' ? Sniffer::mp4Duration($bytes) : null;
        } else {
            $this->fail('bad_type');
        }

        $media->path = $stored['path'];
        $media->url = $stored['url'];
        $media->size = $size;
        $media->save();

        return $media;
    }

    /** @return array{0: array{path: string, url: string}, 1: int, 2: int, 3: string} */
    protected function image(string $bytes, bool $gif): array
    {
        // Dimensions from the header before decoding, so a tiny file that
        // claims to be 50,000 pixels square never reaches the decoder.
        $info = @getimagesizefromstring($bytes);
        if (! $info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 40_000_000) {
            $this->fail('bad_type');
        }

        try {
            $image = $this->images->read($bytes);
        } catch (\Throwable) {
            $this->fail('bad_type');
        }

        $animated = $gif && $image->isAnimated();
        if (! $animated) {
            $image->orient();
        }
        $image->scaleDown(self::MAX_SIDE, self::MAX_SIDE);

        if ($animated) {
            $encoded = (string) $image->encode(new GifEncoder());
            $stored = $this->storage->put($encoded, 'gif');
            $mime = 'image/gif';
        } else {
            $encoded = (string) $image->encode(new WebpEncoder(quality: 82));
            $stored = $this->storage->put($encoded, 'webp');
            $mime = 'image/webp';
        }

        return [$stored, $image->width(), $image->height(), $mime];
    }
}
