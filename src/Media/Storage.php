<?php

namespace Ernestdefoe\Moments\Media;

use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Contracts\Filesystem\Factory;

/**
 * Moments' own disk (`ernestdefoe-moments`, public/assets/moments by default;
 * a forum can point it at any Flarum storage driver). Files get random names
 * and an extension decided by what the file IS, never by what it was called.
 */
class Storage
{
    public const DISK = 'ernestdefoe-moments';

    public function __construct(protected Factory $filesystems)
    {
    }

    public function disk(): Cloud
    {
        /** @var Cloud $disk */
        $disk = $this->filesystems->disk(self::DISK);

        return $disk;
    }

    /** @return array{path: string, url: string} */
    public function put(string $bytes, string $extension): array
    {
        $path = date('Y/m').'/'.bin2hex(random_bytes(16)).'.'.$extension;
        $this->disk()->put($path, $bytes);

        return ['path' => $path, 'url' => $this->disk()->url($path)];
    }

    /** @param list<string> $paths */
    public function deletePaths(array $paths): void
    {
        $paths = array_values(array_filter($paths, fn ($p) => is_string($p) && preg_match('#^\d{4}/\d{2}/[0-9a-f]{32}\.[a-z0-9]{2,5}$#', $p)));
        if ($paths) {
            $this->disk()->delete($paths);
        }
    }
}
