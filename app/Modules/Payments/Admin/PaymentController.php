<?php

namespace App\Modules\Payments\Admin;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Refund;
use App\Modules\Payments\Gateways\GatewayRegistry;
use App\Modules\Payments\Gateways\PaymentGatewayException;
use App\Modules\Payments\Services\PaymentService;
use App\Support\Money;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Payment transactions: list with filters, details (events, status history, refunds), COD cash
 * collection, provider re-verification, provider event log and payment reports.
 * There is deliberately no action that marks an online payment as paid.
 */
class PaymentController extends Controller
{
    public function index(Request $request, GatewayRegistry $gateways)
    {
        $search = trim((string) $request->query('search'));
        [$from, $to] = $this->range($request);

        $payments = Payment::query()
            ->with(['order:id,user_id,status', 'order.user:id,name,phone', 'method:id,name_en,is_legacy'])
            ->when($request->query('provider'), fn ($q, $p) => $q->where('provider', $p))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->boolean('needs_review'), fn ($q) => $q->where('metadata', 'like', '%needs_review%'))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('internal_reference', 'like', "%{$search}%")
                ->orWhere('provider_transaction_id', $search)
                ->orWhere('provider_order_id', $search)
                ->when(ctype_digit(ltrim($search, '#')), fn ($q) => $q->orWhere('order_id', (int) ltrim($search, '#')))))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $totals = Payment::query()
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->groupBy('provider', 'status')
            ->selectRaw('provider, status, COUNT(*) as count, SUM(amount) as amount, SUM(refunded_amount) as refunded')
            ->get();

        return view('dashboard.payments.index', [
            'payments' => $payments,
            'totals' => $totals,
            'statuses' => PaymentStatus::values(),
            'paymob' => ['missing' => $gateways->for(Payment::PROVIDER_PAYMOB)->missingConfiguration(), 'environment' => config('services.paymob.environment'), 'flow' => config('services.paymob.flow')],
            'rejectedEvents' => PaymentEvent::where('result', PaymentEvent::RESULT_REJECTED)->where('created_at', '>=', now()->subDays(7))->count(),
            'pendingRefunds' => Refund::whereIn('status', [Refund::PENDING, Refund::FAILED])->count(),
        ]);
    }

    public function show(Payment $payment)
    {
        $payment->load(['order.user:id,name,phone,email', 'method', 'events', 'history', 'refunds', 'settlement', 'collector:id,name', 'verifier:id,name']);

        return view('dashboard.payments.show', ['payment' => $payment]);
    }

    /** Cash received for a COD order at the counter or from a courier (collection, not settlement). */
    public function collect(Request $request, Payment $payment, PaymentService $payments)
    {
        $data = $request->validate(['amount' => 'required|numeric|min:0.01|decimal:0,2|max:9999999999.99', 'via' => 'required|in:counter,courier', 'note' => 'nullable|string|max:250']);

        try {
            $payments->recordCodCollection($payment, Money::toMinor($data['amount']), $data['via'], PaymentService::ACTOR_ADMIN, auth('admin')->id(), $data['note'] ?? null);
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        AdminAuditLog::record('payment.cod_collected', $payment, ['amount' => $data['amount'], 'via' => $data['via']]);

        return redirect()->back()->with('success', 'Cash collection recorded.');
    }

    /** Ask the provider for the transaction's current state (evidence-based, never a manual "paid"). */
    public function refresh(Payment $payment, PaymentService $payments)
    {
        try {
            $before = $payment->status->value;
            $payment = $payments->refreshFromProvider($payment);
        } catch (PaymentGatewayException|DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        AdminAuditLog::record('payment.refresh', $payment, ['from' => $before, 'to' => $payment->status->value]);

        return redirect()->back()->with('success', $payment->provider_transaction_id ? 'Status checked with the provider.' : 'No provider transaction yet: nothing to check.');
    }

    /** Every provider callback, including rejected (bad signature) ones. */
    public function events(Request $request)
    {
        $events = PaymentEvent::with('payment:id,internal_reference,order_id')
            ->when($request->query('result'), fn ($q, $r) => $q->where('result', $r))
            ->when($request->query('provider'), fn ($q, $p) => $q->where('provider', $p))
            ->latest('id')->paginate(40)->withQueryString();

        return view('dashboard.payments.events', ['events' => $events]);
    }

    public function reports(Request $request)
    {
        [$from, $to] = $this->range($request, defaultDays: 30);

        $base = Payment::query()->where('created_at', '>=', $from)->where('created_at', '<=', $to);

        $byMethod = (clone $base)->groupBy('provider')->selectRaw(
            "provider, COUNT(*) as attempts,
             SUM(CASE WHEN status IN ('paid','partially_refunded','refunded') THEN 1 ELSE 0 END) as paid_count,
             SUM(CASE WHEN status IN ('paid','partially_refunded','refunded') THEN amount ELSE 0 END) as paid_amount,
             SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
             SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired_count,
             SUM(refunded_amount) as refunded_amount")->get();

        $failureReasons = (clone $base)->whereNotNull('failure_reason')->groupBy('failure_reason')
            ->selectRaw('failure_reason, COUNT(*) as count')->orderByDesc('count')->limit(10)->get();

        $cod = [
            'due' => (float) Payment::where('provider', Payment::PROVIDER_COD)->whereIn('status', ['pending', 'partially_paid'])->sum(DB::raw('amount - collected_amount')),
            'collected_unsettled' => (float) Payment::where('provider', Payment::PROVIDER_COD)->whereNull('settled_at')->sum('collected_amount'),
            'settled' => (float) Payment::where('provider', Payment::PROVIDER_COD)->whereNotNull('settled_at')->whereBetween('settled_at', [$from, $to])->sum('collected_amount'),
        ];

        $refunds = Refund::whereBetween('created_at', [$from, $to])->groupBy('status', 'method')
            ->selectRaw('status, method, COUNT(*) as count, SUM(amount) as amount')->get();

        return view('dashboard.payments.reports', compact('byMethod', 'failureReasons', 'cod', 'refunds', 'from', 'to'));
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    private function range(Request $request, ?int $defaultDays = null): array
    {
        $from = $request->date('from') ?? ($defaultDays ? now()->subDays($defaultDays)->startOfDay() : null);
        $to = $request->date('to')?->endOfDay() ?? ($defaultDays ? now()->endOfDay() : null);

        return [$from, $to];
    }
}
