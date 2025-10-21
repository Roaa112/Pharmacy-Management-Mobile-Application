@extends('adminlte::page')

@section('title', 'Body Weight Results')

@section('content_header')
    <h1>Body Weight Results</h1>
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
                    <th>Height</th>
                    <th>Weight</th>
                    <th>Unit</th>
                    <th>BMI</th>
                </tr>
            </thead>
            <tbody>
                @forelse($results as $item)
                    <tr>
                        <td>{{ $item->user->name ?? '-' }}</td>
                        <td>{{ $item->height }}</td>
                        <td>{{ $item->weight }}</td>
                        <td>{{ $item->unit }}</td>
                        <td>{{ $item->bmi_result }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted">لا توجد بيانات</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
  <div class="mt-3 d-flex justify-content-center">
    {{ $results->links() }}
</div>

</div>
@stop
