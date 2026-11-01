<?php

namespace App\Modules\Delivery\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Area;
use App\Models\City;
use App\Models\Country;
use App\Models\DeliveryProvider;
use App\Models\DeliveryRate;
use App\Models\DeliveryZone;
use App\Modules\Delivery\Requests\DeliveryRateRequest;
use App\Modules\Delivery\Requests\DeliveryZoneRequest;
use Illuminate\Support\Facades\DB;

/** Delivery zones (which cities/areas), and their rates (fee, free-over, minimum, ETA, dates). */
class DeliveryZoneController extends Controller
{
    public function index()
    {
        return view('dashboard.delivery.zones', [
            'zones' => DeliveryZone::with(['locations.city', 'locations.area', 'rates.provider'])->orderBy('name')->get(),
            'cities' => City::with('areas')->orderBy('name')->get(),
            'providers' => DeliveryProvider::orderBy('name')->get(),
        ]);
    }

    public function store(DeliveryZoneRequest $request)
    {
        $zone = DB::transaction(function () use ($request) {
            $zone = DeliveryZone::create($request->safe()->only(['name']) + ['country_id' => Country::default()->id, 'is_active' => $request->boolean('is_active', true)]);
            $this->syncLocations($zone, $request);

            return $zone;
        });

        AdminAuditLog::record('delivery.zone.create', $zone, $request->validated());

        return redirect()->back()->with('success', 'Delivery zone created. Add a rate so checkout can use it.');
    }

    public function update(DeliveryZoneRequest $request, DeliveryZone $zone)
    {
        DB::transaction(function () use ($request, $zone) {
            $zone->update($request->safe()->only(['name']) + ['is_active' => $request->boolean('is_active')]);
            $this->syncLocations($zone, $request);
        });

        AdminAuditLog::record('delivery.zone.update', $zone, $request->validated());

        return redirect()->back()->with('success', 'Delivery zone saved.');
    }

    /** Shipments keep their zone as history (nullOnDelete); orders keep the fee they paid. */
    public function destroy(DeliveryZone $zone)
    {
        AdminAuditLog::record('delivery.zone.delete', $zone, ['name' => $zone->name]);
        $zone->delete();

        return redirect()->back()->with('success', 'Delivery zone deleted.');
    }

    public function storeRate(DeliveryRateRequest $request, DeliveryZone $zone)
    {
        $rate = $zone->rates()->create($this->rateData($request));
        AdminAuditLog::record('delivery.rate.create', $rate, $request->validated());

        return redirect()->back()->with('success', 'Rate added.');
    }

    public function updateRate(DeliveryRateRequest $request, DeliveryRate $rate)
    {
        $before = $rate->only(['fee', 'free_over_amount', 'min_order_amount', 'is_active']);
        $rate->update($this->rateData($request));
        AdminAuditLog::record('delivery.rate.update', $rate, ['before' => $before, 'after' => $request->validated()]);

        return redirect()->back()->with('success', 'Rate saved.');
    }

    public function destroyRate(DeliveryRate $rate)
    {
        AdminAuditLog::record('delivery.rate.delete', $rate, $rate->only(['fee', 'delivery_zone_id']));
        $rate->delete();

        return redirect()->back()->with('success', 'Rate deleted.');
    }

    private function rateData(DeliveryRateRequest $request): array
    {
        return [
            'fee' => $request->fee,
            'free_over_amount' => $request->input('free_over_amount') ?: null,
            'min_order_amount' => $request->input('min_order_amount') ?: null,
            'eta_min_days' => $request->input('eta_min_days'),
            'eta_max_days' => $request->input('eta_max_days'),
            'priority' => (int) $request->input('priority', 0),
            'delivery_provider_id' => $request->input('delivery_provider_id') ?: null,
            'is_active' => $request->boolean('is_active', true),
            'starts_at' => $request->input('starts_at') ?: null,
            'ends_at' => $request->input('ends_at') ?: null,
        ];
    }

    private function syncLocations(DeliveryZone $zone, DeliveryZoneRequest $request): void
    {
        $zone->locations()->delete();

        foreach (array_unique($request->input('city_ids', [])) as $cityId) {
            $zone->locations()->create(['city_id' => $cityId]);
        }

        foreach (Area::whereIn('id', $request->input('area_ids', []))->get() as $area) {
            $zone->locations()->create(['city_id' => $area->city_id, 'area_id' => $area->id]);
        }
    }
}
