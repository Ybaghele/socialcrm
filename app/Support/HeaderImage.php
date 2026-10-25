<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores the images customers upload for template headers.
 *
 * Both template samples (used in the Meta submission) and the image a campaign
 * actually sends live on the public disk, because Meta fetches them by URL when
 * the message is delivered. Keeping this in one place means the storage path,
 * extension and URL rules cannot drift between the two screens.
 */
final class HeaderImage
{
    private const DISK = 'public';

    /** Store the upload under a tenant folder and return the relative path. */
    public static function store(UploadedFile $file, string $directory): string
    {
        $extension = match ($file->getMimeType()) {
            'image/png' => 'png',
            default => 'jpg',
        };

        $path = trim($directory, '/').'/'.Str::uuid().'.'.$extension;

        Storage::disk(self::DISK)->put($path, $file->getContent());

        return $path;
    }

    /** The public URL Meta needs, or null when the file has gone missing. */
    public static function url(?string $path): ?string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        $disk = Storage::disk(self::DISK);

        return $disk->exists($path) ? $disk->url($path) : null;
    }

    /** Absolute path for services that read the bytes directly (Meta upload). */
    public static function path(string $path): string
    {
        return Storage::disk(self::DISK)->path($path);
    }

    public static function delete(?string $path): void
    {
        $path = trim((string) $path);

        if ($path !== '') {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}
