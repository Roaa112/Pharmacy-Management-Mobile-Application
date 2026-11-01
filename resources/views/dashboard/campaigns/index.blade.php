@extends('admin.layout')

@section('title', 'Campaigns')
@section('pretitle', 'Marketing')
@section('subtitle', 'Group promotions under shared dates and customer eligibility. A promotion in a campaign applies only while the campaign runs.')

@section('actions')
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createCampaign"><i class="ti ti-plus me-1"></i>New campaign</button>
@endsection

@section('content')
<div class="card">
    @if ($campaigns->isEmpty())
        <x-admin.empty icon="speakerphone" title="No campaigns yet" />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Campaign</th><th>State</th><th>Valid</th><th>Customers</th><th>Promotions</th><th>Orders</th><th>Discount given</th><th class="w-1"></th></tr></thead>
                <tbody>
                @foreach ($campaigns as $campaign)
                    <tr>
                        <td class="fw-medium">{{ $campaign->name_en }}<div class="small text-secondary">{{ $campaign->name_ar }}</div></td>
                        <td><x-admin.status :status="$campaign->state()" /></td>
                        <td class="small text-nowrap">{{ $campaign->starts_at?->format('M j, Y H:i') ?? 'Any time' }}<br><span class="text-secondary">to {{ $campaign->ends_at?->format('M j, Y H:i') ?? 'no end' }}</span></td>
                        <td class="small">{{ ucfirst($campaign->customer_scope) }}</td>
                        <td><a href="{{ route('dashboard.discounts.index', ['campaign' => $campaign->id]) }}">{{ $campaign->promotions_count }}</a></td>
                        <td>{{ $stats[$campaign->id]->orders ?? 0 }}</td>
                        <td><x-admin.money :amount="$stats[$campaign->id]->discount ?? 0" /></td>
                        <td>
                            <div class="btn-list flex-nowrap">
                                <button class="btn btn-sm btn-ghost-primary btn-icon" data-bs-toggle="modal" data-bs-target="#editCampaign{{ $campaign->id }}" title="Edit" aria-label="Edit"><i class="ti ti-edit"></i></button>
                                <x-admin.delete :action="route('dashboard.campaigns.destroy', $campaign)" confirm="Archive this campaign? Its promotions stop applying." />
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <x-admin.pagination :paginator="$campaigns" />
    @endif
</div>
@endsection

@push('modals')
    @foreach ([null, ...$campaigns] as $campaign)
        @php $editing = $campaign !== null; @endphp
        <x-admin.modal :id="$editing ? 'editCampaign'.$campaign->id : 'createCampaign'" :title="$editing ? 'Edit campaign' : 'New campaign'"
                       :action="$editing ? route('dashboard.campaigns.update', $campaign) : route('dashboard.campaigns.store')" :method="$editing ? 'PUT' : 'POST'">
            <div class="row">
                <x-admin.input col="col-md-6 mb-3" name="name_en" label="Name (English)" :value="$campaign?->name_en" required maxlength="255" />
                <x-admin.input col="col-md-6 mb-3" name="name_ar" label="Name (Arabic)" :value="$campaign?->name_ar" maxlength="255" dir="rtl" />
                <x-admin.input col="col-12 mb-3" type="textarea" name="description" label="Description" :value="$campaign?->description" />
                <x-admin.input col="col-md-6 mb-3" type="datetime-local" name="starts_at" label="Starts" :value="$campaign?->starts_at?->format('Y-m-d\TH:i')" />
                <x-admin.input col="col-md-6 mb-3" type="datetime-local" name="ends_at" label="Ends" :value="$campaign?->ends_at?->format('Y-m-d\TH:i')" />
                <div class="col-md-6 mb-3">
                    <label class="form-label">Customers</label>
                    <select name="customer_scope" class="form-select">
                        @foreach (['all' => 'Everyone', 'new' => 'New customers', 'returning' => 'Returning customers'] as $scope => $label)
                            <option value="{{ $scope }}" @selected(($campaign?->customer_scope ?? 'all') === $scope)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <input type="hidden" name="is_active" value="0">
            <label class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" name="is_active" value="1" @checked($campaign?->is_active ?? true)><span class="form-check-label">Active</span></label>
        </x-admin.modal>
    @endforeach
@endpush
