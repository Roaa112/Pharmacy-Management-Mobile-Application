<?php

namespace App\Modules\Delivery\Admin;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\DeliveryProvider;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Modules\Delivery\Services\DeliveryQuote;
use App\Modules\Delivery\Services\OrderFulfilment;
use App\Support\Money;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Shipments from the dashboard. Every change goes through OrderFulfilment as an event, exactly
 * like a provider webhook, so the order follows the same rules either way.
 */
class ShipmentController extends Controller
{
    public function __construct(private OrderFulfilment $fulfilment)
    {
    }

    public function index(Request $request)
    {
        $shipments = Shipment::with(['order:id,status,total_price,user_id', 'order.user:id,name', 'provider:id,name'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->boolean('attention'), fn ($q) => $q->where('needs_attention', true))
            ->when($request->boolean('cod_unsettled'), fn ($q) => $q->whereNotNull('cod_collected_at')->whereNull('cod_settled_at'))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('dashboard.delivery.shipments', [
            'shipments' => $shipments,
            'statuses' => ShipmentStatus::values(),
            'codUnsettled' => (float) Shipment::whereNotNull('cod_collected_at')->whereNull('cod_settled_at')->sum('cod_amount'),
        ]);
    }

    /** For orders placed before shipments existed (or after a failed delivery). */
    public function createForOrder(Order $order)
    {
        abort_if(!$order->address_id && !$order->shipping_address, 422, 'This order is a pickup order.');

        $address = $order->address ?? new \App\Models\Address($order->shipping_address ?? []);
        $quote = new DeliveryQuote(null, null, Money::toMinor($order->delivery_fee));
        $shipment = $this->fulfilment->createShipment($order->loadMissing('user'), $address, $quote);
        AdminAuditLog::record('shipment.create', $shipment, ['order_id' => $order->id]);

        return redirect()->back()->with('success', 'Shipment created.');
    }

    public function assign(Request $request, Shipment $shipment)
    {
        $data = $request->validate([
            'delivery_provider_id' => 'required|integer|exists:delivery_providers,id',
            'tracking_number' => 'nullable|string|max:191',
            'external_reference' => 'nullable|string|max:191',
        ]);

        try {
            $this->fulfilment->assign($shipment, DeliveryProvider::findOrFail($data['delivery_provider_id']), $data['tracking_number'] ?? null, $data['external_reference'] ?? null, auth('admin')->id());
        } catch (\Throwable $e) {
            report($e);

            return redirect()->back()->with('error', 'The shipment could not be handed to this provider: '.$e->getMessage());
        }

        AdminAuditLog::record('shipment.assign', $shipment, $data);

        return redirect()->back()->with('success', 'Shipment handed over.');
    }

    public function addEvent(Request $request, Shipment $shipment)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(ShipmentStatus::values())],
            'note' => 'nullable|string|max:255',
        ]);

        $this->fulfilment->record(
            $shipment, ShipmentStatus::from($data['status']), null, ShipmentEvent::SOURCE_ADMIN, auth('admin')->id(),
            now(), [], 'admin:'.auth('admin')->id().':'.uniqid('', true), $data['note'] ?? null,
        );
        AdminAuditLog::record('shipment.event', $shipment, $data);

        return redirect()->back()->with('success', 'Shipment updated.');
    }

    public function settleCod(Shipment $shipment)
    {
        try {
            $this->fulfilment->settleCod($shipment, auth('admin')->id());
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        AdminAuditLog::record('shipment.cod_settled', $shipment, ['amount' => $shipment->cod_amount]);

        return redirect()->back()->with('success', 'Cash marked as received from the courier.');
    }
}
