<?php

namespace App\Modules\Points\Services;

use DomainException;

/** A redemption needs more usable points than the customer has (nothing was written). */
class InsufficientPoints extends DomainException
{
    public function __construct(public readonly int $needed, public readonly int $available)
    {
        parent::__construct(__('points.insufficient', ['needed' => $needed, 'available' => $available], api_locale()));
    }
}
