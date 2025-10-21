@extends('adminlte::page')

@section('title', 'Body Weight Results')

@section('content_header')
    <h1>Children & Vaccines</h1>
@stop

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <form method="GET" class="d-flex">
            <input type="text" name="search" class="form-control me-2" placeholder="بحث باسم المستخد..." value="{{ request('search') }}">
            <button class="btn btn-primary">بحث</button>
        </form>
    </div>
    <div class="card-body">
          
    <table class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Child Name</th>
                    <th>Gender</th>
                    <th>Birth Date</th>
                    <th>Vaccines</th>
                </tr>
            </thead>
            <tbody>
                @forelse($children as $child)
                    <tr>
                        <td>{{ $child->user->name ?? '-' }}</td>
                        <td>{{ $child->child_name }}</td>
                        <td>{{ $child->gender }}</td>
                        <td>{{ $child->birth_date }}</td>
                        <td>
                            @if($child->vaccines->isNotEmpty())
                                <ul>
                                    @foreach($child->vaccines as $vaccine)
                                        <li>
                                            {{ $vaccine->vaccine_name }} 
                                            ({{ is_string($vaccine->scheduled_date) ? $vaccine->scheduled_date : $vaccine->scheduled_date?->format('Y-m-d') }})
                                            @if($vaccine->is_completed)
                                                <span class="badge bg-success">Done</span>
                                            @else
                                                <span class="badge bg-warning">Pending</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <span class="text-muted">No vaccines</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted">لا توجد نتائج</td></tr>
                @endforelse
            </tbody>
        </table>

    </div>
  <div class="mt-3 d-flex justify-content-center">
    {{ $children->links() }}
</div>

</div>
@stop
