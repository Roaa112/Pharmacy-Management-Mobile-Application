<?php

namespace App\Modules\Admin;

use App\Http\Controllers\Controller;
use App\Models\Medication;
use App\Models\Order;
use App\Models\ReturnRequestImage;
use App\Support\PrivateFiles;
use Illuminate\Support\Facades\URL;

/**
 * Private files through short-lived signed links. The app has always loaded these images by URL
 * without sending a token; a signed URL keeps that working while the file itself stays private
 * (the link expires and cannot be guessed). The "signed" middleware rejects tampered links.
 */
class SignedFileController extends Controller
{
    public const TTL_MINUTES = 60;

    public static function url(string $type, int $id): string
    {
        return URL::temporarySignedRoute('files.signed', now()->addMinutes(self::TTL_MINUTES), ['type' => $type, 'id' => $id]);
    }

    public function show(string $type, int $id)
    {
        $path = match ($type) {
            'payment-receipt' => Order::findOrFail($id)->payment_image,
            'return-image' => ReturnRequestImage::findOrFail($id)->path,
            'medication' => Medication::findOrFail($id)->image_path,
            default => abort(404),
        };

        return PrivateFiles::response($path);
    }
}
