<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Medication extends Model
{
    protected $fillable = ['user_id', 'name', 'notes', 'image_path', 'quantity_per_time', 'repeat_type'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function times()
    {
        return $this->hasMany(MedicationTime::class);
    }

    public function days()
    {
        return $this->hasMany(MedicationDay::class);
    }
   
    public function logs()
    {
        return $this->hasMany(MedicationLog::class);
    }

}
