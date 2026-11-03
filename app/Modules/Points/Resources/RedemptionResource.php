<?php

namespace App\Modules\Points\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RedemptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'source' => $this->source,
            'title' => $this->title(),
            'reward_id' => $this->reward_id,
            'order_id' => $this->order_id,
            'quantity' => (int) $this->quantity,
            'points' => (int) $this->points,
            'value' => $this->value !== null ? (float) $this->value : null,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'fulfilled_at' => $this->fulfilled_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
