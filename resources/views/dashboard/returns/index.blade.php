@extends('adminlte::page')

@section('title', 'طلبات المرتجع')

@section('content_header')
    <h1 class="mb-3">طلبات المرتجع</h1>
@stop

@section('content')

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

@if(session('error'))
    <div class="alert alert-danger">{{ session('error') }}</div>
@endif

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>#</th>
                    <th>رقم الطلب</th>
                    <th>السبب</th>
                    <th>المبلغ المرتجع</th>
                    <th>طريقة الاسترجاع</th>
                    <th>الحالة</th>
                    <th>تاريخ الطلب</th>
                    <th>الصور</th>
                    <th>إجراءات</th>
                       <th>عرض</th>
                </tr>
            </thead>
            <tbody>
                @foreach($refunds as $refund)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $refund->order_id }}</td>
                        <td>{{ $refund->reason }}</td>
                        <td>{{ $refund->refund_amount ?? '-' }}</td>
                        <td>{{ $refund->refund_method ?? '-' }}</td>
                        <td>
                            @if($refund->status == 'pending')
                                <span class="badge bg-warning">قيد المراجعة</span>
                            @elseif($refund->status == 'accepted')
                                <span class="badge bg-success">تم القبول</span>
                            @elseif($refund->status == 'rejected')
                                <span class="badge bg-danger">تم الرفض</span>
                            @endif
                        </td>
                        <td>{{ $refund->created_at->format('Y-m-d') }}</td>
                        <td>
                            @foreach($refund->images as $image)
                                <a href="{{ asset('storage/' . $image->path) }}" target="_blank">
                                    <img src="{{ asset('storage/' . $image->path) }}" width="50" class="img-thumbnail me-1">
                                </a>
                            @endforeach
                        </td>
                        <td>
                            @if($refund->status == 'pending')
                               <form action="{{ route('dashboard.returns.accept', $refund->id) }}" method="POST" style="display:inline-block;">
    @csrf
    <button type="submit" class="btn btn-sm btn-success">قبول</button>
</form>

<form action="{{ route('dashboard.returns.reject', $refund->id) }}" method="POST" style="display:inline-block;">
    @csrf
    <button type="submit" class="btn btn-sm btn-danger">رفض</button>
</form>

                            @else
                                <span class="text-muted">تمت المعالجة</span>
                            @endif
                        </td>
                        <td>
                             <a href="{{ route('dashboard.returns.show', $refund->id) }}" class="btn btn-sm btn-primary">عرض</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- pagination --}}
        <div class="mt-3">
            {{ $refunds->links() }}
        </div>
    </div>
</div>

@stop

@section('css')
<style>
    .table td, .table th {
        vertical-align: middle !important;
    }
    .img-thumbnail {
        border-radius: 0.5rem;
    }
</style>
@stop

@section('js')
<script>
    console.log("قائمة طلبات المرتجع مفعّلة");
</script>
@stop
