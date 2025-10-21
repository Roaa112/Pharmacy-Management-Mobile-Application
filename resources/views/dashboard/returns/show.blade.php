@extends('adminlte::page')

@section('title', 'تفاصيل طلب المرتجع')

@section('content_header')
    <h1>تفاصيل طلب المرتجع</h1>
@stop

@section('content')

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

@if(session('error'))
    <div class="alert alert-danger">{{ session('error') }}</div>
@endif

<div class="card">
    <div class="card-body">
        <div class="mb-3">
            <strong>رقم الطلب:</strong> {{ $refund->order_id }}
        </div>
        <div class="mb-3">
            <strong>السبب:</strong> {{ $refund->reason }}
        </div>
        <div class="mb-3">
            <strong>المبلغ المرتجع:</strong> {{ $refund->refund_amount ?? '-' }}
        </div>
        <div class="mb-3">
            <strong>طريقة الاسترجاع:</strong> {{ $refund->refund_method ?? '-' }}
        </div>
        <div class="mb-3">
            <strong>الحالة:</strong>
            @if($refund->status == 'pending')
                <span class="badge bg-warning">قيد المراجعة</span>
            @elseif($refund->status == 'accepted')
                <span class="badge bg-success">تم القبول</span>
            @elseif($refund->status == 'rejected')
                <span class="badge bg-danger">تم الرفض</span>
            @endif
        </div>
        <div class="mb-3">
            <strong>تاريخ الطلب:</strong> {{ $refund->created_at->format('Y-m-d') }}
        </div>
        <div class="mb-3">
            <strong>الصور المرفقة:</strong><br>
            @forelse($refund->images as $image)
                <a href="{{ asset('storage/' . $image->path) }}" target="_blank">
                    <img src="{{ asset('storage/' . $image->path) }}" width="100" class="img-thumbnail me-2 mb-2">
                </a>
            @empty
                <p class="text-muted">لا توجد صور</p>
            @endforelse
        </div>

        @if($refund->status == 'pending')
            <form action="{{ route('dashboard.returns.accept', $refund->id) }}" method="POST" style="display:inline-block;">
                @csrf
                <button type="submit" class="btn btn-success">قبول الطلب</button>
            </form>

            <form action="{{ route('dashboard.returns.reject', $refund->id) }}" method="POST" style="display:inline-block; margin-right: 10px;">
                @csrf
                <button type="submit" class="btn btn-danger">رفض الطلب</button>
            </form>
        @else
            <div class="alert alert-info mt-3">تمت معالجة هذا الطلب مسبقاً.</div>
        @endif
    </div>
</div>

@stop

@section('css')
    <style>
        .img-thumbnail {
            border-radius: 0.5rem;
        }
    </style>
@stop

@section('js')
    <script>
        console.log("صفحة تفاصيل طلب المرتجع مفعّلة");
    </script>
@stop
