<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * Banner and opening ad images. Validation (MarketingImage::RULES) accepts only raster images
 * (no SVG: it can carry scripts) up to 2 MB and 4000 px; the file is stored on the public disk
 * under a random name whose extension comes from the detected content type, never from the
 * client's file name.
 */
final class MarketingImage
{
    public const RULES = ['image', 'mimes:jpeg,jpg,png,webp', 'max:2048', 'dimensions:max_width=4000,max_height=4000'];

    /** @return string path relative to public/ (what the image columns hold) */
    public static function store(UploadedFile $file, string $folder): string
    {
        return 'storage/'.$file->store("marketing/{$folder}", 'public');
    }
}
