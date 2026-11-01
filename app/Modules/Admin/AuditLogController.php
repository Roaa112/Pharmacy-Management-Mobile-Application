<?php

namespace App\Modules\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        return view('dashboard.admins.audit', [
            'logs' => AdminAuditLog::with('admin:id,name')
                ->when($request->query('action'), fn ($q, $action) => $q->where('action', 'like', $action.'%'))
                ->when($request->query('admin_id'), fn ($q, $id) => $q->where('admin_id', $id))
                ->latest('id')->paginate(50)->withQueryString(),
        ]);
    }
}
