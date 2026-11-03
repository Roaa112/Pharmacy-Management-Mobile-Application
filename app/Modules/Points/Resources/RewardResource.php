<?php

namespace App\Modules\Points\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RewardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'type' => $this->type,
            'title' => $this->title,
            'description' => $this->description,
            'image_url' => $this->imageUrl(),
            'points_required' => (int) $this->points_required,
            'in_stock' => $this->inStock(),
            'stock' => $this->stock,
            'per_customer_limit' => $this->per_customer_limit,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'terms' => $this->terms,
            'product_id' => $this->product_id,
            'product_size_id' => $this->product_size_id,
            // Whether the signed-in customer has enough valid points (set by the controller).
            'affordable' => $this->when(isset($this->affordable), fn () => (bool) $this->affordable),
        ];
    }
}
