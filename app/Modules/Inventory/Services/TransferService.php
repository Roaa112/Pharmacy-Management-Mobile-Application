<?php

namespace App\Modules\Inventory\Services;

use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\StockTransferReceipt;
use App\Support\DocumentNumber;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Warehouse transfers. Units are conserved at every step:
 *  dispatch: source on_hand -q, destination in_transit +q;
 *  receive:  destination in_transit -r, on_hand +r (partial receipts allowed);
 *  close:    the dispatched units never received are written off from in_transit (discrepancy).
 * So on_hand(source) + in_transit + on_hand(destination) + lost stays constant.
 */
class TransferService
{
    public function __construct(private InventoryService $inventory)
    {
    }

    /** @param array<int, int> $quantities product_size_id => units */
    public function request(int $sourceId, int $destinationId, array $quantities, ?string $notes, ?int $adminId): StockTransfer
    {
        if ($sourceId === $destinationId) {
            throw new DomainException('Source and destination must be different warehouses.');
        }

        $quantities = array_filter(array_map('intval', $quantities), fn ($q) => $q !== 0);
        if (!$quantities || min($quantities) < 1) {
            throw new DomainException('Enter at least one product with a quantity of 1 or more.');
        }

        return DB::transaction(function () use ($sourceId, $destinationId, $quantities, $notes, $adminId) {
            $transfer = DocumentNumber::create(StockTransfer::class, 'TR', [
                'source_warehouse_id' => $sourceId, 'destination_warehouse_id' => $destinationId,
                'status' => StockTransfer::REQUESTED, 'notes' => $notes, 'requested_by' => $adminId,
            ]);

            foreach ($quantities as $sizeId => $quantity) {
                $transfer->items()->create(['product_size_id' => $sizeId, 'quantity_requested' => $quantity]);
            }

            return $transfer->fresh('items');
        });
    }

    public function approve(StockTransfer $transfer, ?int $adminId): StockTransfer
    {
        return $this->locked($transfer, [StockTransfer::REQUESTED], function (StockTransfer $transfer) use ($adminId) {
            $transfer->update(['status' => StockTransfer::APPROVED, 'approved_by' => $adminId, 'approved_at' => now()]);
        });
    }

    public function cancel(StockTransfer $transfer): StockTransfer
    {
        return $this->locked($transfer, [StockTransfer::REQUESTED, StockTransfer::APPROVED], function (StockTransfer $transfer) {
            $transfer->update(['status' => StockTransfer::CANCELED]);
        });
    }

    /** Send the requested units. Fails, changing nothing, if the source lacks available units. */
    public function dispatch(StockTransfer $transfer, ?int $adminId): StockTransfer
    {
        return $this->locked($transfer, [StockTransfer::APPROVED], function (StockTransfer $transfer) use ($adminId) {
            foreach ($transfer->items()->orderBy('product_size_id')->get() as $item) {
                $context = ['reference' => $item, 'admin_id' => $adminId, 'reason' => "{$transfer->reference} dispatched"];
                $this->inventory->apply($item->product_size_id, $transfer->source_warehouse_id, ['on_hand' => -$item->quantity_requested],
                    StockMovement::TYPE_TRANSFER_OUT, $context, "transfer-out:{$item->id}");
                $this->inventory->apply($item->product_size_id, $transfer->destination_warehouse_id, ['in_transit' => $item->quantity_requested],
                    StockMovement::TYPE_TRANSFER_DISPATCH, $context, "transfer-dispatch:{$item->id}");
                $item->update(['quantity_dispatched' => $item->quantity_requested]);
            }

            $transfer->update(['status' => StockTransfer::IN_TRANSIT, 'dispatched_by' => $adminId, 'dispatched_at' => now()]);
        });
    }

    /** @param array<int, int> $quantities stock_transfer_item_id => units received now */
    public function receive(StockTransfer $transfer, array $quantities, ?string $notes, ?int $adminId): StockTransfer
    {
        return $this->locked($transfer, [StockTransfer::IN_TRANSIT, StockTransfer::PARTIALLY_RECEIVED], function (StockTransfer $transfer) use ($quantities, $notes, $adminId) {
            $items = StockTransferItem::where('stock_transfer_id', $transfer->id)->lockForUpdate()->get()->keyBy('id');
            $accepted = [];

            foreach ($quantities as $itemId => $quantity) {
                $quantity = (int) $quantity;
                if ($quantity === 0) {
                    continue;
                }
                $item = $items->get((int) $itemId);
                if (!$item || $quantity < 0 || $quantity > $item->in_transit) {
                    throw new DomainException('Received quantities cannot exceed what is still in transit.');
                }
                $accepted[$item->id] = $quantity;
            }

            if (!$accepted) {
                throw new DomainException('Enter at least one received quantity.');
            }

            $receipt = StockTransferReceipt::create([
                'stock_transfer_id' => $transfer->id, 'received_by' => $adminId, 'quantities' => $accepted, 'notes' => $notes,
            ]);

            foreach ($accepted as $itemId => $quantity) {
                $item = $items->get($itemId);
                $this->inventory->apply($item->product_size_id, $transfer->destination_warehouse_id, ['in_transit' => -$quantity, 'on_hand' => $quantity],
                    StockMovement::TYPE_TRANSFER_IN, ['reference' => $item, 'admin_id' => $adminId, 'reason' => "{$transfer->reference} received"],
                    "transfer-in:{$receipt->id}:{$item->id}");
                $item->increment('quantity_received', $quantity);
            }

            $stillInTransit = StockTransferItem::where('stock_transfer_id', $transfer->id)->get()->sum->in_transit;
            $transfer->update([
                'status' => $stillInTransit > 0 ? StockTransfer::PARTIALLY_RECEIVED : StockTransfer::RECEIVED,
                'received_at' => $stillInTransit > 0 ? null : now(),
            ]);
        });
    }

    /** Close the transfer; units still in transit are recorded as lost (a discrepancy needs a reason). */
    public function close(StockTransfer $transfer, ?string $reason, ?int $adminId): StockTransfer
    {
        return $this->locked($transfer, [StockTransfer::PARTIALLY_RECEIVED, StockTransfer::RECEIVED, StockTransfer::IN_TRANSIT], function (StockTransfer $transfer) use ($reason, $adminId) {
            $items = StockTransferItem::where('stock_transfer_id', $transfer->id)->lockForUpdate()->get();

            if ($items->sum->in_transit > 0 && trim((string) $reason) === '') {
                throw new DomainException('Explain the discrepancy: some units were never received.');
            }

            foreach ($items as $item) {
                if ($lost = $item->in_transit) {
                    $this->inventory->apply($item->product_size_id, $transfer->destination_warehouse_id, ['in_transit' => -$lost],
                        StockMovement::TYPE_TRANSFER_LOSS, ['reference' => $item, 'admin_id' => $adminId, 'reason' => "{$transfer->reference}: {$reason}"],
                        "transfer-loss:{$item->id}");
                    $item->update(['quantity_lost' => $item->quantity_lost + $lost]);
                }
            }

            $transfer->update(['status' => StockTransfer::CLOSED, 'closed_at' => now()]);
        });
    }

    private function locked(StockTransfer $transfer, array $from, callable $change): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $from, $change) {
            $transfer = StockTransfer::lockForUpdate()->findOrFail($transfer->id);
            if (!in_array($transfer->status, $from, true)) {
                throw new DomainException("Transfer {$transfer->reference} is {$transfer->status}; this action is not possible.");
            }
            $change($transfer);

            return $transfer->fresh('items');
        });
    }
}
