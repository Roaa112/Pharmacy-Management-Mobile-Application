@extends('adminlte::page')

@section('title', 'قائمة الطلبات')

@section('content_header')
    <h1 class="mb-3">قائمة الطلبات</h1>
@stop

@section('content')

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title">تفا جميع الطلبات</h3>
        <div class="alert alert-success mb-0 p-2">
            <strong>مجموع الأربح المكتملة:</strong> {{ $totalEarned }} EGP
        </div>
    </div>

    <div class="card-body table-responsive p-0">
        <table class="table table-bordered table-hover text-nowrap text-center">
            <thead class="thead-dark">
                <tr>
                    <th>#</th>
                    <th>العميل</th>
                    <th>السعر الجمل</th>
                    <th>صاريف التوصيل</th>
                    <th>الدفع</th>
                    <th>صورة الدفع</th>
                    <th>القاط</th>
                    <th>اهدة</th>
                    <th>الحالة</th>
                    <th>المتجات</th>
                    <th>اعنون</th>
                     <th>الملاحظات</th>
                    <th>تاريخ اطلب</th>
                    <th>تحديث الحالة</th>
                </tr>
            </thead>
            <tbody>
                @forelse($orders as $order)
                <tr>
                    <td>{!! $order->display_id  !!}</td>
                    <td>{{ $order->user->name ?? '-' }}</td>
                    <td>{{ $order->total_price }} EGP</td>
                    <td>{{ $order->delivery_fee }} EGP</td>
                    <td>{{ $order->payment_method == 'zaincash' ? 'زين كاش' : 'كا' }}</td>

                    <td>
                        @if ($order->payment_method == 'zaincash')
                            @if ($order->payment_image)
                                <a href="{{ asset('storage/' . $order->payment_image) }}" target="_blank">
                                    <img src="{{ asset('storage/' . $order->payment_image) }}" alt="صور ادفع" width="80">
                                </a>
                            @else
                                <span class="text-danger">لا تود صرة</span>
                            @endif
                        @else
                            <span class="text-muted">لا ينطبق</span>
                        @endif
                    </td>

                    <td>{{ $order->points_earned ?? 0 }}</td>
                    <td>{{ $order->gift_description ?? '-' }}</td>
                    <td>
                        <span class="badge
                            @switch($order->status)
                                @case('pending') badge-warning @break
                                @case('confirmed') badge-info @break
                                @case('canceled') badge-danger @break
                                @case('completed') badge-success @break
                                @default badge-secondary
                            @endswitch">
                            {{ $order->status }}
                        </span>
                    </td>

                    <td style="text-align: right;">
                        <ul class="list-unstyled">
                            @foreach($order->items as $item)
                                <li class="mb-2">
                                    <strong>{{ $item->product->name ?? 'منتج غير معروف ' }}</strong> (x{{ $item->quantity }})<br>
                                    <small>

                                         مقاس: {{ $item->size->size ?? 'غير متوفر' }}<br>
                                         الأصلي: {{ $item->original_price }} EGP<br>
                                        وقت الطب: {{ $item->price_at_time }} EGP<br>
                                        الإجمالي: {{ $item->total_price }} EGP
                                    </small>
                                </li>
                            @endforeach
                        </ul>
                    </td>

                   <td>
    {{ $order->address->city ?? '' }}<br>
    {{ $order->address->street ?? '' }}<br>
    مبنى: {{ $order->address->building ?? 'لصيدلية' }}<br>

    @if(!empty($order->address->landmark))
        <a href="https://www.google.com/maps/search/?api=1&query={{ urlencode($order->address->landmark) }}" target="_blank">
            {{ $order->address->landmark }}
        </a>
    @endif
</td>

  <td>{{ $order->notes }}</td>
                    <td>{{ $order->created_at->format('Y-m-d H:i') }}</td>

                    <td>
                        <form action="{{ route('dashboard.orders.updateStatus', $order->id) }}" method="POST">
                            @csrf
                            @method('PUT')
                            <select name="status" onchange="this.form.submit()" class="form-control form-control-sm">
                                <option disabled selected>-- اختر --</option>
                                <option value="pending" {{ $order->status === 'pending' ? 'selected' : '' }}>تم تاكيد الطلب  </option>
                                <option value="confirmed" {{ $order->status === 'confirmed' ? 'selected' : '' }}> تم شحن الطلب </option>
                                <option value="canceled" {{ $order->status === 'canceled' ? 'selected' : '' }}>تم الغاء الطلب </option>
                                <option value="completed" {{ $order->status === 'completed' ? 'selected' : '' }}>تم التوصيل</option>
                            </select>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="13" class="text-center text-danger">لا يوجد طلبت</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

   <div class="card-footer d-flex justify-content-center">
    <div class="pagination-wrapper">
        {{ $orders->links() }}
    </div>
</div>

</div>

@stop

@section('css')
<style>
    .table td, .table th {
        vertical-align: middle !important;
    }
</style>
@stop

@section('js')
<script>
    console.log("قائمة الطلبات مفّلة");
</script>
@stop
