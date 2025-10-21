@extends('adminlte::page')

@section('title', 'Body Weight Results')

@section('content_header')
    <h1>Blood Pressure Results</h1>
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
                    <th>Systolic</th>
                    <th>Diastolic</th>
                    <th>Condition Type</th>
                    <th>Measured At</th>
                </tr>
            </thead>
            <tbody>
                @forelse($bloodPressures as $item)
                    <tr>
                        <td>{{ $item->user->name ?? '-' }}</td>
                        <td>{{ $item->systolic }}</td>
                        <td>{{ $item->diastolic }}</td>
                        <td>{{ $item->condition_type }}</td>
                        <td>{{ $item->measured_at?->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted">لا توجد نتائج</td></tr>
                @endforelse
            </tbody>
        </table>

    </div>
  <div class="mt-3 d-flex justify-content-center">
    {{ $bloodPressures->links() }}
</div>

</div>
@stop
