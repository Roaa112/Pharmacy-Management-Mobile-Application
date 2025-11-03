<?php

namespace App\Http\Controllers;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function index()
    {
        return view('home');
    }


    public function home(){

    $totalOrders = Order::count();
    $totalRevenue = Order::where('status', 'completed')
    ->whereDoesntHave('returnRequests', function ($query) {
        $query->whereIn('status', [ 'confirmed']);
    })
    ->sum('total_price');
    $confirmedOrders = Order::where('status', 'confirmed')->count();
    $canceledOrders = Order::where('status', 'canceled')->count();

 $bestSellingProducts = OrderItem::selectRaw('product_id, SUM(quantity) as total_sold')
        ->groupBy('product_id')
        ->orderByDesc('total_sold')
        ->with('product')
        ->take(5)
        ->get();;

    return view('dashboard.index', compact(
        'totalOrders',
        'totalRevenue',
        'confirmedOrders',
        'canceledOrders',
        'bestSellingProducts'
    ));
    }






}
