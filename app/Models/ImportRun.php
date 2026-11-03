<?php

namespace App\Models;

use App\Modules\DataTransfer\Entities\DataEntity;
use App\Modules\DataTransfer\EntityRegistry;
use Illuminate\Database\Eloquent\Model;

/** One import: the uploaded file, its preview (validated_at), its execution and its reports. */
class ImportRun extends Model
{
    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const COMPLETED = 'completed';
    public const COMPLETED_WITH_ERRORS = 'completed_with_errors';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    public const MODES = ['create' => 'Create only', 'update' => 'Update only', 'upsert' => 'Create or update'];

    protected $fillable = ['entity', 'mode', 'format', 'original_name', 'file_path', 'status', 'admin_id'];

    protected $casts = [
        'summary' => 'array',
        'validated_at' => 'datetime',
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

    /** Validated and waiting for the admin to confirm. */
    public function awaitingConfirmation(): bool
    {
        return $this->status === self::PENDING && $this->validated_at !== null;
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::COMPLETED_WITH_ERRORS, self::FAILED, self::CANCELLED], true);
    }
}
