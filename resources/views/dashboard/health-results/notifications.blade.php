@extends('admin.layout')

@section('title', 'Health reminders')
@section('pretitle', 'Health data')
@section('subtitle', 'Reminders sent to customers. Each reminder (one vaccine date, one medication dose) is sent at most once.')

@section('actions')
    @can('settings')<a href="{{ route('dashboard.settings.index') }}" class="btn btn-outline-secondary"><i class="ti ti-settings me-1"></i>Reminder settings</a>@endcan
@endsection

@section('content')
<div class="row row-cards mb-3">
    <div class="col-md-3"><x-admin.stat label="Sent" icon="send" tone="green" :value="number_format($counts['sent'] ?? 0)" /></div>
    <div class="col-md-3"><x-admin.stat label="Failed" icon="alert-triangle" tone="red" :value="number_format($counts['failed'] ?? 0)" /></div>
    <div class="col-md-3"><x-admin.stat label="Registered devices" icon="device-mobile" tone="blue" :value="number_format($devices)" /></div>
    <div class="col-md-3"><x-admin.stat label="Vaccine / medication" icon="bell" tone="secondary"
        :value="($settings->vaccine_reminders_enabled ? 'on' : 'off').' / '.($settings->medication_reminders_enabled ? 'on' : 'off')" /></div>
</div>

<div class="card">
    <div class="card-header">
        <form method="GET" class="d-flex gap-2">
            <select name="type" class="form-select w-auto" aria-label="Type">
                <option value="">All reminders</option>
                <option value="vaccine_reminder" @selected(request('type') === 'vaccine_reminder')>Vaccination</option>
                <option value="medication_reminder" @selected(request('type') === 'medication_reminder')>Medication</option>
            </select>
            <select name="status" class="form-select w-auto" aria-label="Status">
                <option value="">Any status</option>
                @foreach (['sent', 'failed', 'skipped', 'sending'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>@endforeach
            </select>
            <button class="btn btn-outline-primary">Filter</button>
        </form>
    </div>
    @if ($deliveries->isEmpty())
        <x-admin.empty icon="bell-off" title="No reminders yet" text="Reminders are queued by the scheduler (health:send-reminders)." />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Customer</th><th>Reminder</th><th>For</th><th>Status</th><th>Devices</th><th>Sent</th></tr></thead>
                <tbody>
                @foreach ($deliveries as $delivery)
                    <tr>
                        <td>{{ $delivery->user?->name ?? '—' }}</td>
                        <td>{{ $delivery->type === 'vaccine_reminder' ? 'Vaccination' : 'Medication' }}</td>
                        <td class="small">{{ $delivery->occurrence }}</td>
                        <td><span class="badge bg-{{ ['sent' => 'green', 'failed' => 'red', 'skipped' => 'secondary'][$delivery->status] ?? 'blue' }}-lt">{{ $delivery->status }}</span>
                            @if ($delivery->error)<div class="small text-danger">{{ $delivery->error }}</div>@endif</td>
                        <td>{{ $delivery->devices }}</td>
                        <td class="small text-secondary">{{ $delivery->sent_at?->format('M j, H:i') ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <x-admin.pagination :paginator="$deliveries" />
    @endif
</div>
@endsection
