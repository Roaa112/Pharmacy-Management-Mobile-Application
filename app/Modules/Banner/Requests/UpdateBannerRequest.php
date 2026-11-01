<?php

namespace App\Modules\Banner\Requests;

/** Editing keeps the current image unless a new one is uploaded. */
class UpdateBannerRequest extends StoreBannerRequest
{
    protected bool $imageRequired = false;
}
