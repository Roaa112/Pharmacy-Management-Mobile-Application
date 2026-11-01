<?php

namespace App\Modules\Admin;

use App\Http\Controllers\Controller;
use App\Models\Medication;
use App\Models\Order;
use App\Models\ReturnRequestImage;
use App\Support\PrivateFiles;
use Illuminate\Http\Request;

/** A customer's own private files. Anyone else gets 404, as if the file did not exist. */
class ApiPrivateFileController extends Controller
{
    public function paymentReceipt(Request $request, int $order)
    {
        $order = Order::where('user_id', $request->user()->id)->findOrFail($order);

        return PrivateFiles::response($order->payment_image);
    }

    public function returnImage(Request $request, int $image)
    {
        $image = ReturnRequestImage::whereHas('returnRequest', fn ($q) => $q->where('user_id', $request->user()->id))->findOrFail($image);

        return PrivateFiles::response($image->path);
    }

    public function medicationImage(Request $request, int $medication)
    {
        $medication = Medication::where('user_id', $request->user()->id)->findOrFail($medication);

        return PrivateFiles::response($medication->image_path);
    }
}
