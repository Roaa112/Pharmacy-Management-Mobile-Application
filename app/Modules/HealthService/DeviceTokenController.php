<?php

namespace App\Modules\HealthService;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Push notification devices of the signed-in customer, and their notification history. */
class DeviceTokenController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'token' => 'required|string|max:512',
            'platform' => ['required', Rule::in(DeviceToken::PLATFORMS)],
            'locale' => 'nullable|in:ar,en',
        ]);

        // A token belongs to one device; if another account used it before, it moves to this one.
        $device = DeviceToken::updateOrCreate(
            ['token_hash' => DeviceToken::hashOf($data['token'])],
            ['user_id' => $request->user()->id, 'token' => $data['token'], 'platform' => $data['platform'], 'locale' => $data['locale'] ?? api_locale(), 'last_used_at' => now()],
        );

        return response()->json(['message' => api_trans('health.messages.device_registered'), 'id' => $device->id], 201);
    }

    public function destroy(Request $request)
    {
        $request->validate(['token' => 'required|string|max:512']);

        DeviceToken::where('user_id', $request->user()->id)->where('token_hash', DeviceToken::hashOf($request->token))->delete();

        return response()->json(['message' => api_trans('health.messages.device_removed')]);
    }

    public function notifications(Request $request)
    {
        $page = $request->user()->notifications()->latest()->paginate(min(max($request->integer('per_page', 20), 1), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn ($n) => ['id' => $n->id, 'read_at' => $n->read_at, 'created_at' => $n->created_at] + $n->data),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }
}
