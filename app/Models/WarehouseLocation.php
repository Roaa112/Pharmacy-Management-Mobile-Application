<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseLocation extends Model
{
    protected $fillable = ['warehouse_id', 'code', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}
