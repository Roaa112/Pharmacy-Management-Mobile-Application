@extends('adminlte::page')

@section('title', 'Body Weight Results')

@section('content_header')
    <h1>Pregnancy Calculations</h1>
@stop

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <form method="GET" class="d-flex">
            <input type="text" name="search" class="form-control me-2" placeholder="ابحث باسم المستخدم..." value="{{ request('search') }}">
            <button class="btn btn-primary">بحث</button>
        </form>
    </div>
    <div class="card-body">
          
   <table class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Last Period Date</th>
                    <th>Due Date</th>
                 
                </tr>
            </thead>
            <tbody>
                @forelse($pregnancies as $item)
                    <tr>
                        <td>{{ $item->user->name ?? '-' }}</td>
                        <td>{{ $item->last_period_date?->format('Y-m-d') }}</td>
                        <td>{{ $item->due_date?->format('Y-m-d') }}</td>
                      
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-muted">لا توجد نتائج</td></tr>
                @endforelse
            </tbody>
        </table>

    </div>
  <div class="mt-3 d-flex justify-content-center">
    {{ $pregnancies->links() }}
</div>

</div>
@stop
