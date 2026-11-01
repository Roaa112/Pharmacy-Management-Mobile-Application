<?php

namespace App\Modules\HealthService\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\NotificationDelivery;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Reminder delivery log (who was reminded of what, and whether it went out). */
class NotificationLogController extends Controller
{
    public function index(Request $request)
    {
        return view('dashboard.health-results.notifications', [
            'deliveries' => NotificationDelivery::with('user:id,name')
                ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
                ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
                ->latest()->paginate(30)->withQueryString(),
            'counts' => NotificationDelivery::select('status', DB::raw('COUNT(*) as total'))->groupBy('status')->pluck('total', 'status'),
            'devices' => DeviceToken::count(),
            'settings' => Setting::current(),
        ]);
    }
}
