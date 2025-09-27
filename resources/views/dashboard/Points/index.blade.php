@extends('adminlte::page')

@section('title', 'النقاط')

@section('content_header')
    <h1>النقاط المكتسبة</h1>
@stop

@section('content')
<div class="card">
    {{-- <div class="card-header d-flex justify-content-between">
        <h3 class="card-title">قائمة النقاط الصالحة</h3>
        <span class="badge badge-success">
            إجمالي النقاط: {{ $valid_points_total ?? 0 }}
        </span>
    </div> --}}

    <div class="card-body">
        {{-- فلتر البحث --}}
        <form method="GET" action="{{ route('dashboard.points.index') }}" class="mb-3">
            <div class="row">
                <div class="col-md-4">
                    <input type="text" name="user_name" class="form-control"
                           placeholder="ابحث باسم العميل"
                           value="{{ request('user_name') }}">
                </div>
                <div class="col-md-4">
                    <input type="text" name="order_id" class="form-control"
                           placeholder="ابحث برقم الطلب"
                           value="{{ request('order_id') }}">
                </div>
                <div class="col-md-4 d-flex">
                    <button type="submit" class="btn btn-primary mr-2">
                        <i class="fas fa-search"></i> بحث
                    </button>
                    <a href="{{ route('dashboard.points.index') }}" class="btn btn-secondary">
                        <i class="fas fa-undo"></i> إعادة تعيين
                    </a>
                </div>
            </div>
        </form>

        {{-- جدول النقاط --}}
        @if($orders->isNotEmpty())
            <form action="{{ route('dashboard.points.redeemMultiple') }}" method="POST" id="redeem-multiple-form">
                @csrf
                @method('PATCH')

                <table class="table table-striped table-hover">
                    <thead class="thead-dark">
                        <tr>
                            <th>
                                <input type="checkbox" id="select-all">
                            </th>
                            <th>#</th>
                            <th>العميل</th>
                            <th>رقم الطلب</th>
                            <th>النقاط</th>
                            <th>تاريخ الإنشاء</th>
                            <th>تاريخ الانتهاء</th>
                            <th>الإجراء الفردي</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($orders as $index => $order)
                            <tr>
                                <td>
                                    <input type="checkbox" name="order_ids[]" value="{{ $order['order_id'] }}" class="order-checkbox">
                                </td>
                                <td>{{ $index + 1 }}</td>
                                <td>{{ $order['user_name'] ?? '-' }}</td>
                                <td>{{ $order['display_id'] }}</td>
                                <td><span class="badge badge-info">{{ number_format($order['points_earned']) }}</span></td>
                                <td>{{ $order['created_at'] }}</td>
                                <td>{{ $order['expires_at'] }}</td>
                                <td>
                                    <form action="{{ route('dashboard.points.redeem', $order['order_id']) }}" method="POST" style="display:inline;" onsubmit="return confirm('هل أنت متأكد من استبدال النقاط لهذا الطلب؟');">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn btn-sm btn-success">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="p-3">
                    <button type="submit" class="btn btn-success" id="bulk-redeem-btn">
                        <i class="fas fa-check-double"></i> استبدال المحدد
                    </button>
                </div>
            </form>
        @else
            <div class="p-3 text-center text-muted">
                لا توجد نقاط مكتسبة حالياً.
            </div>
        @endif
    </div>
</div>
@stop

@section('css')
    <style>
        .badge-success { font-size: 1rem; }
        table td, table th { vertical-align: middle !important; }
    </style>
@stop

@section('js')
<script>
    // تحديد الكل
    document.getElementById('select-all').addEventListener('change', function () {
        document.querySelectorAll('.order-checkbox').forEach(cb => cb.checked = this.checked);
    });

    // تأكيد قبل الإرسال لو مفيش حاجة محددة
    document.getElementById('bulk-redeem-btn').addEventListener('click', function (e) {
        const checkedBoxes = document.querySelectorAll('.order-checkbox:checked');
        if (checkedBoxes.length === 0) {
            e.preventDefault();
            alert('من فضلك اختر على الأقل طلب واحد لاستبدال نقاطه.');
        } else {
            if (!confirm('هل تريد استبدال النقاط لكل الطلبات المحددة؟')) {
                e.preventDefault();
            }
        }
    });
</script>
@stop
