<?php

namespace App\Models;

use App\Modules\DataTransfer\Entities\DataEntity;
use App\Modules\DataTransfer\EntityRegistry;
use Illuminate\Database\Eloquent\Model;

/** One export: what was asked for (filters, columns, format), by whom, and the file. */
class ExportRun extends Model
{
    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';

    protected $fillable = ['entity', 'format', 'filters', 'columns', 'status', 'admin_id'];

    protected $casts = [
        'filters' => 'array',
        'columns' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function admin()
    {
        return $this->belongsTo(Admin::class);
    }

    public function entityDefinition(): ?DataEntity
    {
        return EntityRegistry::find($this->entity);
    }

    public function fileName(): string
    {
        return "{$this->entity}-{$this->created_at->format('Ymd-His')}.{$this->format}";
    }
}
