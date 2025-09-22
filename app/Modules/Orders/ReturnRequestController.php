<?php
namespace App\Modules\Orders;

use Carbon\Carbon;

use App\Models\ProductSize;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use App\Models\{Order, OrderItem, Cart, Address, DeliveryPrice, Setting, DiscountRule};
use App\Models\ReturnRequest;

class ReturnRequestController extends Controller
{



public function store(Request $request)
{
    $request->validate([
        'order_id' => 'required|exists:orders,id',
        'reason' => 'required',
        'note' => 'nullable',
        'refund_amount' => 'nullable|numeric',
        'refund_method' => 'nullable|string',
        'address' => 'nullable|string',
        'images.*' => 'nullable|image|max:2048',
    ]);

    $return = ReturnRequest::create([
        'user_id' => auth()->id(),
        'order_id' => $request->order_id,
        'reason' => $request->reason,
        'note' => $request->note,
        'refund_amount' => $request->refund_amount,
        'refund_method' => $request->refund_method,
        'address' => $request->address,
    ]);

    if ($request->hasFile('images')) {
        foreach ($request->file('images') as $image) {
            $path = $image->store('return_images', 'public');
            $return->images()->create(['path' => $path]);
        }
    }

    return response()->json(['message' => 'تم إرسال طلب المرتجع بنجاح', 'data' => $return], 201);
}

public function index()
{
    $returns = ReturnRequest::with('order')
        ->where('user_id', auth()->id())
        ->select('id', 'order_id', 'reason', 'status', 'refund_amount', 'created_at')
        ->get();

    return response()->json($returns);
}

public function show($id)
{
    $return = ReturnRequest::with(['order', 'images'])
        ->where('user_id', auth()->id())
        ->findOrFail($id);

    return response()->json($return);
}


public function cancel($id)
{
    $return = ReturnRequest::where('user_id', auth()->id())->findOrFail($id);
    if ($return->status !== 'pending') {
        return response()->json(['message' => 'لا يمكن إلغاء الطلب بعد معالجته'], 400);
    }

    $return->update(['status' => 'canceled']);

    return response()->json(['message' => 'تم إلغاء طلب المرتجع بنجاح']);
}


////// admin



 public function adminindex(Request $request)
    {
        $status = $request->get('status');

        $refunds = ReturnRequest::with(['user', 'order'])
            ->when($status, fn($q) => $q->where('status', $status))
            ->latest()
            ->paginate(20);

        return view('dashboard.returns.index', compact('refunds'));
    }

    public function adminshow($id)
    {
        $refund = ReturnRequest::with(['user', 'order', 'images'])->findOrFail($id);
        return view('dashboard.returns.show', compact('refund'));
    }

    public function accept($id)
    {
        $return = ReturnRequest::findOrFail($id);
        $return->update(['status' => 'accepted']);

        // Optional: send notification

        return redirect()->back()->with('success', 'تم قبول المرتجع بنجاح');
    }

    public function reject(Request $request, $id)
    {
        $request->validate(['reason' => 'nullable|string']);
        $return = ReturnRequest::findOrFail($id);
        $return->update([
            'status' => 'rejected',
            'note' => $request->reason ?? $return->note,
        ]);

        // Optional: send notification

        return redirect()->back()->with('error', 'تم رفض المرتجع');
    }
}
