<?php

namespace Ernestdefoe\Moments\Media;

use Carbon\Carbon;
use Ernestdefoe\Moments\Media;
use Ernestdefoe\Moments\Settings;
use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * With fof/upload enabled, photos and video are uploaded through it (its
 * storage adapters, its own permissions and mime rules), and the moment
 * attaches the file by id. 🚨 Only a file the member uploaded themselves, and
 * only an image (not SVG) or MP4/WebM video, is accepted.
 */
class FofBridge
{
    public function __construct(
        protected ExtensionManager $extensions,
        protected ConnectionInterface $db,
        protected Settings $settings,
        protected TranslatorInterface $translator
    ) {
    }

    public function enabled(): bool
    {
        return $this->extensions->isEnabled('fof-upload');
    }

    public function attach(User $actor, int $fileId): Media
    {
        $file = $this->db->table('fof_upload_files')->where('id', $fileId)->where('actor_id', $actor->id)->first();

        $kind = null;
        if ($file) {
            $type = strtolower((string) $file->type);
            $kind = match (true) {
                in_array($type, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'], true) => 'image',
                in_array($type, ['video/mp4', 'video/webm'], true) => 'video',
                default => null,
            };
        }

        $url = $file ? (string) preg_replace('/[\x00-\x1F\x7F]/', '', (string) $file->url) : '';
        if (! $file || ! $kind || ! preg_match('#^(https?://|/)#i', $url)) {
            throw new ValidationException(['file' => $this->translator->trans('ernestdefoe-moments.api.bad_type')]);
        }

        $media = new Media();
        $media->user_id = $actor->id;
        $media->source = 'fof';
        $media->kind = $kind;
        $media->mime = strtolower((string) $file->type);
        $media->url = $url;
        $media->size = (int) ($file->size ?? 0);
        $media->created_at = Carbon::now();
        $media->save();

        return $media;
    }
}
