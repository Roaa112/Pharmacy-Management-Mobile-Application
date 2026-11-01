<?php

namespace App\Modules\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Order;
use App\Models\ReturnRequestImage;
use App\Support\PrivateFiles;

/** Dashboard access to private uploads; the route middleware checks the permission. */
class PrivateFileController extends Controller
{
    public function paymentReceipt(Order $order)
    {
        AdminAuditLog::record('file.payment_receipt', $order);

        return PrivateFiles::response($order->payment_image);
    }

    public function returnImage(ReturnRequestImage $image)
    {
        return PrivateFiles::response($image->path);
    }
}
