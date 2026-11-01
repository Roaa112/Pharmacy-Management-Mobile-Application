<?php

namespace App\Modules\FlashSale\Requests;

/** Editing keeps the saved end when none is sent, and allows sales that already started. */
class UpdateFlashSaleRequest extends StoreFlashSaleRequest
{
    protected bool $endRequired = false;
}
