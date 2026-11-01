<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One vaccine of one child: a snapshot of the schedule template taken when the child was added
 * (vaccine_schedule_item_id + schedule_version say which template entry and version).
 */
class Vaccine extends Model
{
    use HasFactory;

    protected $fillable = [
        'child_id', 'vaccine_schedule_item_id', 'schedule_version', 'vaccine_name', 'vaccine_name_en',
        'description', 'description_en', 'scheduled_date', 'reminder_days_before', 'is_completed', 'completed_at',
    ];

    protected $casts = [
        'scheduled_date' => 'datetime',
        'completed_at' => 'datetime',
        // is_completed is deliberately not cast: the app has always received it as 0/1.
    ];

    public function child()
    {
        return $this->belongsTo(Child::class);
    }

    public function scheduleItem()
    {
        return $this->belongsTo(VaccineScheduleItem::class, 'vaccine_schedule_item_id')->withTrashed();
    }
}
