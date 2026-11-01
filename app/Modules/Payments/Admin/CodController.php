<?php

namespace App\Modules\Payments\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\CodSettlement;
use App\Models\DeliveryProvider;
use App\Models\Payment;
use App\Models\Shipment;
use App\Modules\Payments\Services\CodSettlementService;
use App\Support\Money;
use DomainException;
use Illuminate\Http\Request;

/**
 * Cash on delivery reconciliation: what is still due from customers, what couriers collected
 * and have not handed over (per provider), settlements and their discrepancies.
 */
class CodController extends Controller
{
    public function index(Request $request)
    {
        $providerId = $request->integer('provider') ?: null;
        $providerOf = Shipment::query()->select('delivery_provider_id')->whereColumn('shipments.order_id', 'payments.order_id')->latest('id')->limit(1);

        $unsettled = Payment::query()
            ->where('provider', Payment::PROVIDER_COD)->where('collected_amount', '>', 0)->whereNull('settled_at')
            ->with(['order:id,user_id,address_id', 'order.user:id,name', 'order.latestShipment.provider:id,name'])
            ->when($providerId, fn ($q) => $q->whereIn('order_id', Shipment::where('delivery_provider_id', $providerId)->select('order_id')))
            ->orderBy('collected_at')
            ->get();

        $byProvider = Payment::query()
            ->where('provider', Payment::PROVIDER_COD)->where('collected_amount', '>', 0)->whereNull('settled_at')
            ->selectRaw('('.$providerOf->toSql().') as provider_id, COUNT(*) as count, SUM(collected_amount) as amount', $providerOf->getBindings())
            ->groupBy('provider_id')->get();

        $due = Payment::where('provider', Payment::PROVIDER_COD)->whereIn('status', ['pending', 'partially_paid'])
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount - collected_amount), 0) as amount')->first();
        $failedCollections = Payment::where('provider', Payment::PROVIDER_COD)->where('collection_status', Payment::COLLECTION_FAILED)->count();

        $settlements = CodSettlement::with(['provider:id,name', 'settler:id,name'])->withCount('payments')->latest('id')->paginate(15);

        return view('dashboard.cod.index', [
            'unsettled' => $unsettled,
            'byProvider' => $byProvider,
            'providers' => DeliveryProvider::orderBy('name')->get(['id', 'name'])->keyBy('id'),
            'providerId' => $providerId,
            'due' => $due,
            'failedCollections' => $failedCollections,
            'settlements' => $settlements,
            'discrepancy' => (float) CodSettlement::sum('difference'),
        ]);
    }

    public function settle(Request $request, CodSettlementService $settlements)
    {
        $data = $request->validate([
            'payment_ids' => 'required|array|min:1',
            'payment_ids.*' => 'integer',
            'received_amount' => 'required|numeric|min:0',
            'provider_id' => 'nullable|integer|exists:delivery_providers,id',
            'received_reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $settlement = $settlements->settle($data['payment_ids'], Money::toMinor($data['received_amount']), $data['provider_id'] ?? null,
                $data['received_reference'] ?? null, $data['notes'] ?? null, auth('admin')->id());
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        AdminAuditLog::record('cod.settle', $settlement, $settlement->only(['expected_amount', 'received_amount', 'difference']));

        return redirect()->route('dashboard.cod.settlements.show', $settlement)
            ->with((float) $settlement->difference === 0.0 ? 'success' : 'error', (float) $settlement->difference === 0.0
                ? 'Settlement recorded.'
                : 'Settlement recorded with a difference of '.number_format((float) $settlement->difference, 2).'.');
    }

    public function show(CodSettlement $settlement)
    {
        $settlement->load(['provider:id,name', 'settler:id,name', 'payments.order.user:id,name']);

        return view('dashboard.cod.settlement', compact('settlement'));
    }
}
