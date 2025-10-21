<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;


use Illuminate\Database\Eloquent\Factories\HasFactory;

class Vaccine extends Model
{
    use HasFactory;

    protected $fillable = [
        'child_id', 'vaccine_name', 'description', 'scheduled_date', 'is_completed'
    ];
protected $casts = [
        'scheduled_date' => 'datetime', // ✅ أضف السطر ده
    ];

    public function child()
    {
        return $this->belongsTo(Child::class);
    }
}

