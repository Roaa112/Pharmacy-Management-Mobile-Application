@extends('adminlte::page')

@section('title', 'Body Weight Results')

@section('content_header')
    <h1>Ovulation Results</h1>
@stop

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <form method="GET" class="d-flex">
            <input type="text" name="search" class="form-control me-2" placeholder="احث باسم المستخدم..." value="{{ request('search') }}">
            <button class="btn btn-primary">بحث</button>
        </form>
    </div>
    <div class="card-body">
            <table class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Start Day of Cycle</th>
                    <th>Cycle Length</th>
                </tr>
            </thead>
            <tbody>
                @forelse($ovulations as $item)
                    <tr>
                        <td>{{ $item->user->name ?? '-' }}</td>
                        <td>{{ $item->start_day_of_cycle?->format('Y-m-d') }}</td>
                        <td>{{ $item->cycle_length }}</td>
                  

                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-muted">ل توجد نتائج</td></tr>
                @endforelse
            </tbody>
        </table>

    </div>
  <div class="mt-3 d-flex justify-content-center">
    {{ $ovulations->links() }}
</div>

</div>
@stop
