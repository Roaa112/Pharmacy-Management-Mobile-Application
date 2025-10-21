@extends('adminlte::page')

@section('title', 'Health Services')

@section('content_header')
    <h1>Health Services Results</h1>
@stop

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title">All Health Services Data</h3>

        {{-- 🔍 فلتر باسم المستخدم --}}
        <form action="{{ url('dashboard/health-results') }}" method="GET" class="d-flex">
            <input 
                type="text" 
                name="search" 
                value="{{ request('search') }}" 
                class="form-control me-2" 
                placeholder="ابحث باسم المستخدم...">
            <button class="btn btn-primary" type="submit">بحث</button>
        </form>
    </div>

    <div class="card-body">

        {{-- Body Weight Results --}}
        <h4 class="mt-4 mb-2 text-primary">Body Weight Results</h4>
        <table class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Height</th>
                    <th>Weight</th>
                    <th>Unit</th>
                    <th>BMI Result</th>
                    <th>Created At</th>
                </tr>
            </thead>
            <tbody>
                @forelse($bodyWeights as $item)
                    <tr>
                        <td>{{ $item->user->name ?? '-' }}</td>
                        <td>{{ $item->height }}</td>
                        <td>{{ $item->weight }}</td>
                        <td>{{ $item->unit }}</td>
                        <td>{{ $item->bmi_result }}</td>
                        <td>{{ $item->created_at->format('Y-m-d') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted">لا توجد نتائج</td></tr>
                @endforelse
            </tbody>
        </table>

        {{-- Blood Sugar --}}
        <h4 class="mt-5 mb-2 text-primary">Blood Sugar Measurements</h4>
        <table class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Value</th>
                    <th>Condition Type</th>
                    <th>Measured At</th>
                </tr>
            </thead>
            <tbody>
                @forelse($bloodSugars as $item)
                    <tr>
                        <td>{{ $item->user->name ?? '-' }}</td>
                        <td>{{ $item->value }}</td>
                        <td>{{ $item->condition_type }}</td>
                        <td>{{ $item->measured_at?->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-muted">لا توجد نتائج</td></tr>
                @endforelse
            </tbody>
        </table>

        {{-- Blood Pressure --}}
        <h4 class="mt-5 mb-2 text-primary">Blood Pressure Measurements</h4>
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

        {{-- Ovulation --}}
        <h4 class="mt-5 mb-2 text-primary">Ovulation Results</h4>
        <table class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Start Day of Cycle</th>
                    <th>Cycle Length</th>
                    <th>Result</th>
                </tr>
            </thead>
            <tbody>
                @forelse($ovulations as $item)
                    <tr>
                        <td>{{ $item->user->name ?? '-' }}</td>
                        <td>{{ $item->start_day_of_cycle?->format('Y-m-d') }}</td>
                        <td>{{ $item->cycle_length }}</td>
                   <td>
    @if($item->result)
     @php
    $decoded = is_string($item->result) ? json_decode($item->result, true) : $item->result;
@endphp


        @if(is_array($decoded))
            <ul class="list-unstyled mb-0">
                @foreach($decoded as $key => $value)
                    @if(is_array($value))
                        <li><strong>{{ $key }}:</strong>
                            <ul>
                                @foreach($value as $subKey => $subValue)
                                    @if(is_array($subValue))
                                        <li><strong>{{ $subKey }}:</strong>
                                            <ul>
                                                @foreach($subValue as $innerKey => $innerValue)
                                                    <li>{{ $innerKey }}: {{ $innerValue }}</li>
                                                @endforeach
                                            </ul>
                                        </li>
                                    @else
                                        <li>{{ $subKey }}: {{ $subValue }}</li>
                                    @endif
                                @endforeach
                            </ul>
                        </li>
                    @else
                        <li><strong>{{ $key }}:</strong> {{ $value }}</li>
                    @endif
                @endforeach
            </ul>
        @else
            <pre>{{ $ovulation->result }}</pre>
        @endif
    @else
        -
    @endif
</td>

                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-muted">لا توجد نتائج</td></tr>
                @endforelse
            </tbody>
        </table>

        {{-- Pregnancy --}}
        <h4 class="mt-5 mb-2 text-primary">Pregnancy Calculations</h4>
        <table class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Last Period Date</th>
                    <th>Due Date</th>
                    <th>Result Summary</th>
                </tr>
            </thead>
            <tbody>
                @forelse($pregnancies as $item)
                    <tr>
                        <td>{{ $item->user->name ?? '-' }}</td>
                        <td>{{ $item->last_period_date?->format('Y-m-d') }}</td>
                        <td>{{ $item->due_date?->format('Y-m-d') }}</td>
                        <td>{{ $item->result['summary'] ?? '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-muted">لا توجد نتائج</td></tr>
                @endforelse
            </tbody>
        </table>

        {{-- Children & Vaccines --}}
        <h4 class="mt-5 mb-2 text-primary">Children & Vaccines</h4>
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
</div>
@stop

@section('css')
<style>
    h4 {
        border-left: 4px solid #007bff;
        padding-left: 8px;
    }
</style>
@stop

@section('js')
<script>
    console.log("Health Services Dashboard Loaded!");
</script>
@stop
