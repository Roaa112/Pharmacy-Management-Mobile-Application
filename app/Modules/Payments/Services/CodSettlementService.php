<?php

namespace App\Modules\Payments\Services;

use App\Models\CodSettlement;
use App\Models\Payment;
use App\Models\Shipment;
use App\Support\DocumentNumber;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * COD settlement: the cash a courier (or the counter) collected is handed over to the pharmacy.
 * A delivered order is not settled; only a settlement marks its cash as received. The amount
 * actually received is recorded next to what was expected, so shortfalls stay visible.
 */
class CodSettlementService
{
    /** @param list<int> $paymentIds */
    public function settle(array $paymentIds, int $receivedMinor, ?int $providerId, ?string $receivedReference, ?string $notes, ?int $adminId): CodSettlement
    {
        $paymentIds = array_values(array_unique(array_map('intval', $paymentIds)));

        if (!$paymentIds) {
            throw new DomainException('Select the collected payments being settled.');
        }
        if ($receivedMinor < 0) {
            throw new DomainException('The received amount cannot be negative.');
        }

        return DB::transaction(function () use ($paymentIds, $receivedMinor, $providerId, $receivedReference, $notes, $adminId) {
            $payments = Payment::whereIn('id', $paymentIds)->orderBy('id')->lockForUpdate()->get();

            if ($payments->count() !== count($paymentIds)) {
                throw new DomainException('Some selected payments do not exist.');
            }

            foreach ($payments as $payment) {
                if (!$payment->isCod() || Money::toMinor($payment->collected_amount) <= 0) {
                    throw new DomainException("Payment {$payment->internal_reference} has no collected cash.");
                }
                if ($payment->settled_at) {
                    throw new DomainException("Payment {$payment->internal_reference} was already settled.");
                }
            }

            $expectedMinor = $payments->sum(fn (Payment $p) => Money::toMinor($p->collected_amount));

            $settlement = DocumentNumber::create(CodSettlement::class, 'CS', [
                'delivery_provider_id' => $providerId,
                'expected_amount' => Money::fromMinor($expectedMinor),
                'received_amount' => Money::fromMinor($receivedMinor),
                'difference' => Money::fromMinor($receivedMinor - $expectedMinor),
                'received_reference' => $receivedReference,
                'notes' => $notes,
                'settled_by' => $adminId,
                'settled_at' => now(),
            ]);

            Payment::whereIn('id', $paymentIds)->update(['cod_settlement_id' => $settlement->id, 'settled_at' => now()]);
            // The shipment keeps showing "settled" as before.
            Shipment::whereIn('order_id', $payments->pluck('order_id'))->whereNotNull('cod_collected_at')->whereNull('cod_settled_at')
                ->update(['cod_settled_at' => now()]);

            foreach ($payments as $payment) {
                app(PaymentService::class)->recordInternalEvent($payment, 'cod.settled', ['settlement' => $settlement->reference, 'amount' => $payment->collected_amount]);
            }

            return $settlement;
        });
    }
}
