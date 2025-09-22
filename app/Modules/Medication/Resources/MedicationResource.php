<?php

namespace App\Modules\Medication\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class MedicationResource extends JsonResource
{
    // public function toArray($request)
    // {
    //     return [
    //         'id' => $this->id,
    //         'user_id' => $this->user_id,
    //         'name' => $this->name,
    //         'notes' => $this->notes,
    //         'quantity_per_time' => $this->quantity_per_time,
    //         'repeat_type' => $this->repeat_type,
    //         'image_path' => $this->image_path ? asset('storage/' . $this->image_path) : null,
    //         'created_at' => $this->created_at,
    //         'updated_at' => $this->updated_at,
    //         'times' => $this->times->pluck('time'), // جيب مصفوفة أوقات فقط
    //         'days' => $this->days->pluck('day'),   // يجيب مصفوفة أيام فقط
    //     ];
    // }

    public function toArray($request)
{
    return [
        'id' => $this->id,
        'user_id' => $this->user_id,
        'name' => $this->name,
        'notes' => $this->notes,
      'image_url' =>asset('storage/' . $this->image_path),

        'quantity_per_time' => $this->quantity_per_time,
        'repeat_type' => $this->repeat_type,
        'times' => $this->times, // ده هيكون جاهز فعلاً من الـ controller
        'days' => $this->days->pluck('day'),
    ];
}

}
