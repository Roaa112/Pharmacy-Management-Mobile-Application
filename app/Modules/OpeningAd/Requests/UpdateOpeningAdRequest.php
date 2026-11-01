<?php

namespace App\Modules\OpeningAd\Requests;

/** Editing keeps the current image unless a new one is uploaded. */
class UpdateOpeningAdRequest extends StoreOpeningAdRequest
{
    protected bool $imageRequired = false;
}
