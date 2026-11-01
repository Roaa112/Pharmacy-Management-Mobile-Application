<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sensitive uploads (payment receipts, return photos, medication photos) are stored on the
 * private "local" disk and only served through routes that check who is asking. Files uploaded
 * before this change are still on the public disk: they are found there until the
 * files:make-private command moves them.
 */
final class PrivateFiles
{
    public const PRIVATE_DISK = 'local';
    public const LEGACY_DISK = 'public';

    public static function response(?string $path): Response
    {
        abort_if(!$path || str_contains($path, '..'), 404);

        foreach ([self::PRIVATE_DISK, self::LEGACY_DISK] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return Storage::disk($disk)->response($path, null, [
                    'Cache-Control' => 'private, no-store',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }
        }

        abort(404);
    }

    public static function delete(?string $path): void
    {
        if ($path) {
            Storage::disk(self::PRIVATE_DISK)->delete($path);
        }
    }
}
