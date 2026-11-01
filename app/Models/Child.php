<?php

namespace App\Models;

use App\Enums\Gender;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Child extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'child_name', 'gender', 'birth_date'];

    protected $appends = ['gender_code'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function vaccines()
    {
        return $this->hasMany(Vaccine::class);
    }

    /** Stable code next to the stored Arabic value ("ذكر" / "أنثى"). */
    public function getGenderCodeAttribute(): ?string
    {
        return Gender::fromInput($this->gender)?->value;
    }
}
