<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use LogicException;

class AdminAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['admin_id', 'action', 'subject_type', 'subject_id', 'changes', 'ip', 'user_agent'];

    protected $casts = ['changes' => 'array'];

    protected static function booted()
    {
        static::updating(fn () => throw new LogicException('Audit log entries cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Audit log entries cannot be deleted.'));
    }

    public function admin()
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * Record a sensitive admin action. $subject may be a model or null. $adminId defaults to the
     * signed-in admin (queued jobs pass the admin who started the work).
     */
    public static function record(string $action, ?Model $subject = null, array $changes = [], ?int $adminId = null): self
    {
        $request = app()->runningInConsole() && !app()->runningUnitTests() ? null : request();

        return static::create([
            'admin_id' => $adminId ?? Auth::guard('admin')->id(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'changes' => $changes ?: null,
            'ip' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 255) ?: null : null,
        ]);
    }
}
