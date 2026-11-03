<?php

namespace App\Modules\Points\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One points movement in the customer's history. Never exposes which admin made it. */
class LoyaltyEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isLot = $this->remaining_points !== null;

        return [
            'id' => $this->id,
            'type' => $this->type,
            'points' => (int) $this->points,
            'description' => $this->description,
            'order_id' => $this->order_id,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'status' => $isLot ? $this->status : null,
            'remaining_points' => $isLot ? (int) $this->remaining_points : null,
            'earned_at' => $this->earned_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'redeemed_at' => $this->redeemed_at?->toIso8601String(),
            'reversed_at' => $this->reversed_at?->toIso8601String(),
        ];
    }
}
