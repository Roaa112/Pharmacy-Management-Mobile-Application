<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class MedicationTime extends Model
{
    protected $fillable = ['medication_id', 'time'];

    public function medication()
    {
        return $this->belongsTo(Medication::class);
    }
}
