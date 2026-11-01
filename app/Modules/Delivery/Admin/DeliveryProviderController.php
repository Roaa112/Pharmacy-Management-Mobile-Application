<?php

namespace App\Modules\Delivery\Admin;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Country;
use App\Models\DeliveryProvider;
use App\Models\ProviderStatusMap;
use App\Models\ShipmentEvent;
use App\Modules\Delivery\Providers\ProviderRegistry;
use App\Modules\Delivery\Requests\DeliveryProviderRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Delivery companies (or our own team), their webhook secret and status vocabulary. */
class DeliveryProviderController extends Controller
{
    public function index()
    {
        $providers = DeliveryProvider::with('statusMaps')->withCount('shipments')->orderBy('name')->get();

        // Statuses providers sent that nobody mapped yet: shown so an admin can map them.
        $unmapped = ShipmentEvent::whereNull('shipment_events.status')->whereNotNull('shipment_events.raw_status')
            ->join('shipments', 'shipments.id', '=', 'shipment_events.shipment_id')
            ->select('shipments.delivery_provider_id', 'shipment_events.raw_status', DB::raw('COUNT(*) as total'))
            ->groupBy('shipments.delivery_provider_id', 'shipment_events.raw_status')
            ->get()
            ->groupBy('delivery_provider_id');

        return view('dashboard.delivery.providers', [
            'providers' => $providers,
            'unmapped' => $unmapped,
            'adapters' => array_keys(ProviderRegistry::ADAPTERS),
            'statuses' => ShipmentStatus::values(),
        ]);
    }

    public function store(DeliveryProviderRequest $request)
    {
        $provider = DeliveryProvider::create($this->data($request));
        $this->ensureSingleDefault($provider);
        AdminAuditLog::record('delivery.provider.create', $provider, $request->safe()->except('webhook_secret'));

        return redirect()->back()->with('success', 'Delivery provider created.');
    }

    public function update(DeliveryProviderRequest $request, DeliveryProvider $provider)
    {
        $provider->update($this->data($request, $provider));
        $this->ensureSingleDefault($provider);
        AdminAuditLog::record('delivery.provider.update', $provider, $request->safe()->except('webhook_secret') + ['secret_changed' => $request->filled('webhook_secret')]);

        return redirect()->back()->with('success', 'Delivery provider saved.');
    }

    public function storeStatusMap(Request $request, DeliveryProvider $provider)
    {
        $data = $request->validate([
            'external_status' => ['required', 'string', 'max:100'],
            'internal_status' => ['required', Rule::in(ShipmentStatus::values())],
        ]);

        $map = ProviderStatusMap::updateOrCreate(
            ['delivery_provider_id' => $provider->id, 'external_status' => trim($data['external_status'])],
            ['internal_status' => $data['internal_status']],
        );
        AdminAuditLog::record('delivery.status_map.save', $map, $data);

        // Past events with that status are not re-applied: mapping affects future updates only.
        return redirect()->back()->with('success', 'Status mapping saved. It applies to updates received from now on.');
    }

    public function destroyStatusMap(ProviderStatusMap $map)
    {
        AdminAuditLog::record('delivery.status_map.delete', $map, $map->only(['external_status', 'internal_status']));
        $map->delete();

        return redirect()->back()->with('success', 'Status mapping deleted.');
    }

    private function data(DeliveryProviderRequest $request, ?DeliveryProvider $provider = null): array
    {
        $data = $request->safe()->only(['name', 'code', 'type', 'adapter', 'tracking_url_template']) + [
            'country_id' => Country::default()?->id,
            'supports_cod' => $request->boolean('supports_cod'),
            'is_active' => $request->boolean('is_active'),
            'is_default' => $request->boolean('is_default'),
        ];

        if ($request->filled('webhook_secret') || !$provider) {
            $data['webhook_secret'] = $request->input('webhook_secret') ?: null;
        }

        return $data;
    }

    private function ensureSingleDefault(DeliveryProvider $provider): void
    {
        if ($provider->is_default) {
            DeliveryProvider::whereKeyNot($provider->id)->update(['is_default' => false]);
        }
    }
}
