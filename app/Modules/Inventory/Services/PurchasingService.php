<?php

namespace App\Modules\Inventory\Services;

use App\Models\ProductSize;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\SupplierReturnItem;
use Illuminate\Database\UniqueConstraintViolationException;
use App\Support\DocumentNumber;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Procurement: purchase orders, receiving (full or partial) and supplier returns.
 *
 * A purchase order is a commitment, not stock: creating or approving it changes no quantity.
 * Only a receipt moves units into the warehouse (on_hand, or damaged for units that arrived
 * broken) and updates the SKU's weighted average cost.
 */
class PurchasingService
{
    public function __construct(private InventoryService $inventory)
    {
    }

    /**
     * @param  array<int, array{product_size_id: int, quantity: int, unit_cost: numeric}>  $lines
     */
    public function createOrder(Supplier $supplier, int $warehouseId, array $lines, array $attributes, ?int $adminId): PurchaseOrder
    {
        if (!$lines) {
            throw new DomainException('A purchase order needs at least one line.');
        }

        return DB::transaction(function () use ($supplier, $warehouseId, $lines, $attributes, $adminId) {
            $order = DocumentNumber::create(PurchaseOrder::class, 'PO', [
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouseId,
                'status' => PurchaseOrder::DRAFT,
                'currency_code' => $attributes['currency_code'] ?? $supplier->currency_code ?? config('pharmacy.currency'),
                'order_date' => $attributes['order_date'] ?? now()->toDateString(),
                'expected_at' => $attributes['expected_at'] ?? null,
                'tax_amount' => $attributes['tax_amount'] ?? 0,
                'shipping_cost' => $attributes['shipping_cost'] ?? 0,
                'notes' => $attributes['notes'] ?? null,
                'created_by' => $adminId,
            ]);

            $subtotalMinor = 0;
            foreach ($this->mergeLines($lines) as $sizeId => [$quantity, $unitCost]) {
                $lineMinor = Money::toMinor($unitCost) * $quantity;
                $subtotalMinor += $lineMinor;
                $order->items()->create([
                    'product_size_id' => $sizeId, 'quantity_ordered' => $quantity,
                    'unit_cost' => $unitCost, 'line_total' => Money::fromMinor($lineMinor),
                ]);
            }

            $order->update([
                'subtotal' => Money::fromMinor($subtotalMinor),
                'total' => Money::fromMinor($subtotalMinor + Money::toMinor($order->tax_amount) + Money::toMinor($order->shipping_cost)),
            ]);

            return $order->fresh('items');
        });
    }

    /** Approve / send to the supplier. Still no stock change. */
    public function markOrdered(PurchaseOrder $order, ?int $adminId): PurchaseOrder
    {
        return $this->transitionLocked($order, [PurchaseOrder::DRAFT], function (PurchaseOrder $order) use ($adminId) {
            $order->update(['status' => PurchaseOrder::ORDERED, 'approved_by' => $adminId, 'ordered_at' => now()]);
        });
    }

    public function cancel(PurchaseOrder $order): PurchaseOrder
    {
        return $this->transitionLocked($order, [PurchaseOrder::DRAFT, PurchaseOrder::ORDERED], function (PurchaseOrder $order) {
            $order->update(['status' => PurchaseOrder::CANCELED, 'canceled_at' => now()]);
        });
    }

    /** Stop expecting the outstanding units of a partially received order. */
    public function close(PurchaseOrder $order): PurchaseOrder
    {
        return $this->transitionLocked($order, [PurchaseOrder::PARTIALLY_RECEIVED, PurchaseOrder::RECEIVED], function (PurchaseOrder $order) {
            $order->update(['status' => PurchaseOrder::CLOSED, 'closed_at' => now()]);
        });
    }

    /**
     * Record goods received against an order. Partial receipts are allowed; receiving more than
     * is outstanding on a line is refused.
     *
     * Each line may name its lot and expiry date (as printed on the packs): the units go to that
     * batch; a batch that is already expired (or would be on arrival) is refused. Without them the
     * units go to the SKU's batch of unknown lot and expiry.
     *
     * @param  array<int, array{received?: int, damaged?: int, lot_number?: ?string, expiry_date?: ?string}>  $quantities  purchase_order_item_id => units
     */
    public function receive(PurchaseOrder $order, array $quantities, ?string $supplierInvoiceNumber, ?string $notes, ?int $adminId): PurchaseReceipt
    {
        return DB::transaction(function () use ($order, $quantities, $supplierInvoiceNumber, $notes, $adminId) {
            $order = PurchaseOrder::lockForUpdate()->findOrFail($order->id);

            if (!$order->canReceive()) {
                throw new DomainException("Purchase order {$order->reference} is {$order->status}; only ordered purchase orders can be received.");
            }

            $items = PurchaseOrderItem::where('purchase_order_id', $order->id)->lockForUpdate()->get()->keyBy('id');
            $lines = [];

            foreach ($quantities as $itemId => $line) {
                $good = (int) ($line['received'] ?? 0);
                $damaged = (int) ($line['damaged'] ?? 0);

                if ($good === 0 && $damaged === 0) {
                    continue;
                }

                $item = $items->get((int) $itemId);
                if (!$item || $good < 0 || $damaged < 0) {
                    throw new DomainException('Received quantities must be whole numbers for lines of this purchase order.');
                }
                if ($good + $damaged > $item->outstanding) {
                    throw new DomainException("Only {$item->outstanding} units are outstanding on one of the lines.");
                }

                $expiry = filled($line['expiry_date'] ?? null) ? \Illuminate\Support\Carbon::parse($line['expiry_date'])->toDateString() : null;
                if ($expiry !== null && $expiry <= \App\Support\StoreClock::now()->toDateString()) {
                    throw new DomainException("Lot ".($line['lot_number'] ?? '')." expired on {$expiry}: expired medicine cannot be received into stock.");
                }

                if ($expiry === null && $good > 0 && config('pharmacy.require_expiry_on_receipt')) {
                    throw new DomainException('Enter the expiry date printed on the packs for every line received.');
                }

                $lines[$item->id] = [$item, $good, $damaged, $line['lot_number'] ?? null, $expiry];
            }

            if (!$lines) {
                throw new DomainException('Enter at least one received quantity.');
            }

            $receipt = DocumentNumber::create(PurchaseReceipt::class, 'GRN', [
                'purchase_order_id' => $order->id, 'warehouse_id' => $order->warehouse_id,
                'supplier_invoice_number' => $supplierInvoiceNumber, 'notes' => $notes,
                'received_by' => $adminId, 'received_at' => now(),
            ]);

            // SKU rows are locked in id order (the same order as checkout).
            uasort($lines, fn ($a, $b) => $a[0]->product_size_id <=> $b[0]->product_size_id);

            foreach ($lines as [$item, $good, $damaged, $lot, $expiry]) {
                $receiptItem = PurchaseReceiptItem::create([
                    'purchase_receipt_id' => $receipt->id, 'purchase_order_item_id' => $item->id,
                    'product_size_id' => $item->product_size_id, 'quantity_received' => $good,
                    'quantity_damaged' => $damaged, 'unit_cost' => $item->unit_cost,
                ]);

                if ($good > 0) {
                    $this->updateAverageCost($item->product_size_id, $good, $item->unit_cost);
                }

                $batch = $this->inventory->batchFor($item->product_size_id, $lot, $expiry,
                    ['supplier_id' => $order->supplier_id, 'purchase_receipt_item_id' => $receiptItem->id, 'unit_cost' => $item->unit_cost]);

                $this->inventory->apply($item->product_size_id, $order->warehouse_id, ['on_hand' => $good, 'damaged' => $damaged],
                    StockMovement::TYPE_PURCHASE_RECEIPT,
                    ['reference' => $receiptItem, 'admin_id' => $adminId, 'unit_cost' => $item->unit_cost, 'reason' => "{$receipt->reference} for {$order->reference}", 'batch_id' => $batch->id],
                    "purchase-receipt:{$receiptItem->id}");

                $item->increment('quantity_received', $good + $damaged);
                if ($damaged) {
                    $item->increment('quantity_damaged', $damaged);
                }
            }

            $outstanding = PurchaseOrderItem::where('purchase_order_id', $order->id)->get()->sum->outstanding;
            $order->update(['status' => $outstanding > 0 ? PurchaseOrder::PARTIALLY_RECEIVED : PurchaseOrder::RECEIVED]);

            return $receipt->fresh('items');
        });
    }

    /**
     * @param  array<int, array{product_size_id: int, quantity: int, source_bucket: string, unit_cost?: numeric}>  $lines
     */
    public function createReturn(Supplier $supplier, int $warehouseId, array $lines, ?string $reason, ?int $purchaseOrderId, ?int $adminId): SupplierReturn
    {
        if (!$lines) {
            throw new DomainException('A supplier return needs at least one line.');
        }

        return DB::transaction(function () use ($supplier, $warehouseId, $lines, $reason, $purchaseOrderId, $adminId) {
            $return = DocumentNumber::create(SupplierReturn::class, 'SR', [
                'supplier_id' => $supplier->id, 'warehouse_id' => $warehouseId, 'purchase_order_id' => $purchaseOrderId,
                'status' => SupplierReturn::DRAFT, 'reason' => $reason, 'created_by' => $adminId,
            ]);

            $creditMinor = 0;
            foreach ($lines as $line) {
                if (!in_array($line['source_bucket'], ['on_hand', 'damaged', 'quarantined'], true) || (int) $line['quantity'] <= 0) {
                    throw new DomainException('Each returned line needs a quantity and where the units come from.');
                }
                $cost = $line['unit_cost'] ?? ProductSize::whereKey($line['product_size_id'])->value('cost_price') ?? 0;
                $creditMinor += Money::toMinor($cost) * (int) $line['quantity'];
                $return->items()->create([
                    'product_size_id' => $line['product_size_id'], 'source_bucket' => $line['source_bucket'],
                    'quantity' => (int) $line['quantity'], 'unit_cost' => $cost,
                ]);
            }

            $return->update(['credit_amount' => Money::fromMinor($creditMinor)]);

            return $return->fresh('items');
        });
    }

    /** The goods leave the warehouse; the credit counts against the supplier balance. */
    public function dispatchReturn(SupplierReturn $return, ?int $adminId): SupplierReturn
    {
        return DB::transaction(function () use ($return, $adminId) {
            // Supplier first (the lock supplier payments take): the credit and a payment checked
            // against the balance cannot interleave.
            Supplier::lockForUpdate()->find(SupplierReturn::whereKey($return->id)->value('supplier_id'));
            $return = SupplierReturn::lockForUpdate()->findOrFail($return->id);

            if ($return->status !== SupplierReturn::DRAFT) {
                throw new DomainException("Supplier return {$return->reference} was already {$return->status}.");
            }

            foreach ($return->items()->orderBy('product_size_id')->get() as $item) {
                $this->inventory->apply($item->product_size_id, $return->warehouse_id, [$item->source_bucket => -$item->quantity],
                    StockMovement::TYPE_SUPPLIER_RETURN,
                    ['reference' => $item, 'admin_id' => $adminId, 'unit_cost' => $item->unit_cost, 'reason' => "{$return->reference}: ".($return->reason ?? 'returned to supplier')],
                    "supplier-return:{$item->id}");

                if ($item->purchase_order_item_id) {
                    PurchaseOrderItem::whereKey($item->purchase_order_item_id)->increment('quantity_returned', $item->quantity);
                }
            }

            $return->update(['status' => SupplierReturn::DISPATCHED, 'dispatched_by' => $adminId, 'dispatched_at' => now()]);

            return $return;
        });
    }

    public function cancelReturn(SupplierReturn $return): SupplierReturn
    {
        return DB::transaction(function () use ($return) {
            $return = SupplierReturn::lockForUpdate()->findOrFail($return->id);
            if ($return->status !== SupplierReturn::DRAFT) {
                throw new DomainException('Only a draft supplier return can be canceled.');
            }
            $return->update(['status' => SupplierReturn::CANCELED]);

            return $return;
        });
    }

    /**
     * A payment to a supplier, against one of its invoices or on account.
     *  - invoice: never more than is left on the invoice;
     *  - on account: never more than the supplier is owed, unless the admin marks it an advance
     *    ($data['advance']) and advances are allowed (pharmacy.supplier_advance_payments); an
     *    advance is recorded as such (is_advance) and makes the balance a credit.
     * Every payment of a supplier is decided under the supplier row lock (then the invoice: one
     * lock order), so simultaneous payments cannot both pass a check; a repeated request_key (the
     * form's one-time key) returns the payment already recorded instead of a second one.
     */
    public function recordSupplierPayment(Supplier $supplier, array $data, ?int $adminId): SupplierPayment
    {
        $advance = (bool) ($data['advance'] ?? false);
        unset($data['advance'], $data['is_advance']);

        try {
            return DB::transaction(function () use ($supplier, $data, $adminId, $advance) {
                // The lock first: every read below then sees what was committed before it.
                $supplier = Supplier::lockForUpdate()->findOrFail($supplier->id);

                if (filled($data['request_key'] ?? null) && ($existing = SupplierPayment::where('request_key', $data['request_key'])->first())) {
                    return $existing;
                }

                $amountMinor = Money::toMinor($data['amount']);
                $isAdvance = false;

                if ($data['supplier_invoice_id'] ?? null) {
                    $invoice = SupplierInvoice::where('supplier_id', $supplier->id)->lockForUpdate()->findOrFail($data['supplier_invoice_id']);
                    $paidMinor = Money::toMinor(SupplierPayment::where('supplier_invoice_id', $invoice->id)->sum('amount'));
                    if ($paidMinor + $amountMinor > Money::toMinor($invoice->amount)) {
                        throw new DomainException('The payment is larger than what is left on that invoice.');
                    }
                } elseif ($amountMinor > max(0, $supplier->balanceMinor())) {
                    if (!config('pharmacy.supplier_advance_payments')) {
                        throw new DomainException('The payment is larger than what is owed to this supplier ('.number_format(Money::fromMinor(max(0, $supplier->balanceMinor())), 2).'); advance payments are not allowed.');
                    }
                    if (!$advance) {
                        throw new DomainException('The payment is larger than what is owed to this supplier ('.number_format(Money::fromMinor(max(0, $supplier->balanceMinor())), 2).'). Tick "advance payment" if it is meant as an advance.');
                    }
                    $isAdvance = true;
                }

                return $supplier->payments()->create($data + ['created_by' => $adminId, 'is_advance' => $isAdvance]);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            // The same form submitted twice at the same moment: the other request recorded it.
            return SupplierPayment::where('request_key', $data['request_key'])->firstOrFail();
        }
    }

    /** Weighted average cost over the units on hand in all warehouses. */
    private function updateAverageCost(int $sizeId, int $quantity, $unitCost): void
    {
        $size = ProductSize::lockForUpdate()->findOrFail($sizeId);
        $onHand = (int) $size->balances()->lockForUpdate()->sum('on_hand'); // locking read: committed units, not a stale snapshot
        $currentMinor = Money::toMinor($size->cost_price ?? $unitCost);
        $newMinor = intdiv($currentMinor * $onHand + Money::toMinor($unitCost) * $quantity + intdiv($onHand + $quantity, 2), $onHand + $quantity);

        ProductSize::whereKey($sizeId)->update(['cost_price' => Money::fromMinor($newMinor)]);
    }

    /** @return array<int, array{0: int, 1: string}> size id => [quantity, unit cost] */
    private function mergeLines(array $lines): array
    {
        $merged = [];
        foreach ($lines as $line) {
            $quantity = (int) ($line['quantity'] ?? 0);
            if ($quantity <= 0 || !isset($line['product_size_id'], $line['unit_cost']) || !is_numeric($line['unit_cost']) || $line['unit_cost'] < 0) {
                throw new DomainException('Each line needs a product, a quantity of at least 1 and a unit cost.');
            }
            $sizeId = (int) $line['product_size_id'];
            if (isset($merged[$sizeId])) {
                throw new DomainException('The same product size appears twice; combine the lines.');
            }
            $merged[$sizeId] = [$quantity, (string) $line['unit_cost']];
        }

        return $merged;
    }

    private function transitionLocked(PurchaseOrder $order, array $from, callable $change): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $from, $change) {
            $order = PurchaseOrder::lockForUpdate()->findOrFail($order->id);
            if (!in_array($order->status, $from, true)) {
                throw new DomainException("Purchase order {$order->reference} is {$order->status}; this action is not possible.");
            }
            $change($order);

            return $order;
        });
    }
}
