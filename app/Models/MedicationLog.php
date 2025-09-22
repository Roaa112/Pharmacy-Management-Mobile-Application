<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicationLog extends Model
{
    protected $fillable = [
        'user_id',
        'medication_id',
        'shown_date',
        'time',
        'status',
    ];

   
    protected $casts = [
        'shown_date' => 'date',
        'time' => 'datetime:H:i:s',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function medication()
    {
        return $this->belongsTo(Medication::class);
    }
}
