<?php

namespace App\Modules\Payments\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Payment;
use App\Models\Refund;
use App\Modules\Payments\Gateways\PaymentGatewayException;
use App\Modules\Payments\Services\RefundService;
use App\Support\Money;
use DomainException;
use Illuminate\Http\Request;

/** Refunds: queue, details, processing (gateway or cash/manual with a reference), cancellation. Permission: refunds. */
class RefundController extends Controller
{
    public function index(Request $request)
    {
        $refunds = Refund::with(['order:id,user_id', 'order.user:id,name', 'payment:id,internal_reference,provider', 'requester:id,name', 'processor:id,name'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('method'), fn ($q, $m) => $q->where('method', $m))
            ->latest('id')->paginate(25)->withQueryString();

        $open = Refund::whereIn('status', [Refund::PENDING, Refund::PROCESSING, Refund::FAILED])->selectRaw('COUNT(*) as count, COALESCE(SUM(amount), 0) as amount')->first();

        return view('dashboard.refunds.index', compact('refunds', 'open'));
    }

    public function show(Refund $refund)
    {
        $refund->load(['order.user:id,name,phone', 'payment.events', 'returnRequest', 'requester:id,name', 'processor:id,name']);

        return view('dashboard.refunds.show', compact('refund'));
    }

    /** A refund not linked to a return (goodwill, overcharge...), against a received payment. */
    public function store(Request $request, Payment $payment, RefundService $refunds)
    {
        $data = $request->validate(['amount' => 'required|numeric|min:0.01', 'reason' => 'required|string|max:250']);

        try {
            $refund = $refunds->requestForPayment($payment, Money::toMinor($data['amount']), $data['reason'], auth('admin')->id());
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        AdminAuditLog::record('refund.request', $refund, $refund->only(['amount', 'method', 'reason']));

        return redirect()->route('dashboard.refunds.show', $refund)->with('success', 'Refund requested.');
    }

    public function process(Request $request, Refund $refund, RefundService $refunds)
    {
        $data = $request->validate(['external_reference' => 'nullable|string|max:100']);

        try {
            $refund = $refunds->process($refund, auth('admin')->id(), $data['external_reference'] ?? null);
        } catch (DomainException|PaymentGatewayException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        AdminAuditLog::record('refund.process', $refund, ['status' => $refund->status, 'external_reference' => $refund->external_reference]);

        return redirect()->back()->with($refund->status === Refund::FAILED ? 'error' : 'success', match ($refund->status) {
            Refund::SUCCEEDED => 'Refund completed.',
            Refund::PROCESSING => 'Refund sent to the provider; it completes when the provider confirms.',
            default => 'The refund failed: '.$refund->failure_reason,
        });
    }

    public function cancel(Request $request, Refund $refund, RefundService $refunds)
    {
        $data = $request->validate(['reason' => 'required|string|max:250']);

        try {
            $refunds->cancel($refund, auth('admin')->id(), $data['reason']);
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        AdminAuditLog::record('refund.cancel', $refund, ['reason' => $data['reason']]);

        return redirect()->back()->with('success', 'Refund canceled.');
    }
}
