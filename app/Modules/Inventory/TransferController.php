<?php

namespace App\Modules\Inventory;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\ProductSize;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Modules\Inventory\Services\TransferService;
use DomainException;
use Illuminate\Http\Request;

class TransferController extends Controller
{
    public function __construct(private TransferService $transfers)
    {
    }

    public function index(Request $request)
    {
        return view('dashboard.transfers.index', [
            'transfers' => StockTransfer::with(['source:id,code,name', 'destination:id,code,name'])->withCount('items')
                ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
                ->latest('id')->paginate(25)->withQueryString(),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(),
            'sizes' => $this->sizeOptions(),
            'statuses' => StockTransfer::STATUSES,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'source_warehouse_id' => 'required|integer|exists:warehouses,id',
            'destination_warehouse_id' => 'required|integer|exists:warehouses,id|different:source_warehouse_id',
            'lines' => 'required|array|min:1',
            'lines.*.product_size_id' => 'required|integer|exists:product_sizes,id',
            'lines.*.quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:1000',
        ]);

        $quantities = [];
        foreach ($data['lines'] as $line) {
            $quantities[$line['product_size_id']] = ($quantities[$line['product_size_id']] ?? 0) + (int) $line['quantity'];
        }

        return $this->run(fn () => $this->transfers->request($data['source_warehouse_id'], $data['destination_warehouse_id'], $quantities, $data['notes'] ?? null, auth('admin')->id()),
            'transfer.request', 'Transfer requested.');
    }

    public function show(StockTransfer $transfer)
    {
        $transfer->load(['source', 'destination', 'items.size.product' => fn ($q) => $q->basic()->withTrashed(), 'receipts.receiver:id,name']);

        return view('dashboard.transfers.show', compact('transfer'));
    }

    public function approve(StockTransfer $transfer)
    {
        return $this->run(fn () => $this->transfers->approve($transfer, auth('admin')->id()), 'transfer.approve', 'Transfer approved.');
    }

    public function dispatchTransfer(StockTransfer $transfer)
    {
        return $this->run(fn () => $this->transfers->dispatch($transfer, auth('admin')->id()), 'transfer.dispatch', 'Transfer dispatched: the units are in transit.');
    }

    public function receive(Request $request, StockTransfer $transfer)
    {
        $data = $request->validate(['quantities' => 'required|array', 'quantities.*' => 'nullable|integer|min:0', 'notes' => 'nullable|string|max:1000']);

        return $this->run(fn () => $this->transfers->receive($transfer, array_filter($data['quantities'], fn ($q) => $q !== null), $data['notes'] ?? null, auth('admin')->id()),
            'transfer.receive', 'Received units recorded.');
    }

    public function close(Request $request, StockTransfer $transfer)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:255']);

        return $this->run(fn () => $this->transfers->close($transfer, $data['reason'] ?? null, auth('admin')->id()), 'transfer.close', 'Transfer closed.');
    }

    public function cancel(StockTransfer $transfer)
    {
        return $this->run(fn () => $this->transfers->cancel($transfer), 'transfer.cancel', 'Transfer canceled.');
    }

    private function run(callable $action, string $audit, string $message)
    {
        try {
            $transfer = $action();
        } catch (DomainException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        AdminAuditLog::record($audit, $transfer, ['status' => $transfer->status]);

        return redirect()->route('dashboard.transfers.show', $transfer)->with('success', $message);
    }

    private function sizeOptions()
    {
        return ProductSize::whereHas('product')->with(['product' => fn ($q) => $q->basic()->select('id', 'name_en')])
            ->orderBy('product_id')->get(['id', 'product_id', 'size', 'sku']);
    }
}
