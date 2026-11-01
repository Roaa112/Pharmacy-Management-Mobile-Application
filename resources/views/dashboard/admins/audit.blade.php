@extends('admin.layout')

@section('title', 'Audit log')
@section('pretitle', 'Security')
@section('subtitle', 'Sensitive dashboard actions: order and payment changes, refunds, points, settings, delivery, health content and views of customers\' health records. Entries cannot be changed or deleted.')

@section('content')
<div class="card">
    <div class="card-header">
        <form method="GET" class="d-flex gap-2">
            <input name="action" class="form-control" placeholder="Action starts with… (e.g. health)" value="{{ request('action') }}" aria-label="Action">
            <button class="btn btn-outline-primary">Filter</button>
        </form>
    </div>
    @if ($logs->isEmpty())
        <x-admin.empty icon="list-search" title="Nothing recorded yet" text="Actions appear here as admins use the dashboard." />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>When</th><th>Admin</th><th>Action</th><th>Subject</th><th>Details</th><th>IP</th></tr></thead>
                <tbody>
                @foreach ($logs as $log)
                    <tr>
                        <td class="small text-nowrap">{{ $log->created_at?->format('M j, Y H:i') }}</td>
                        <td>{{ $log->admin?->name ?? 'System' }}</td>
                        <td><code>{{ $log->action }}</code></td>
                        <td class="small">{{ $log->subject_type ? $log->subject_type.' #'.$log->subject_id : '—' }}</td>
                        <td class="small text-secondary text-break" style="max-width: 28rem">{{ $log->changes ? \Illuminate\Support\Str::limit(json_encode($log->changes, JSON_UNESCAPED_UNICODE), 200) : '—' }}</td>
                        <td class="small">{{ $log->ip }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <x-admin.pagination :paginator="$logs" />
    @endif
</div>
@endsection
