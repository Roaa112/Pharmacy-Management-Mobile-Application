@extends('admin.layout')

@section('title', 'Pregnancy weeks')
@section('pretitle', 'Health content')
@section('subtitle', 'What the app shows for each range of pregnancy weeks. The week and due date themselves are calculated (Naegele\'s rule) and cannot be edited.')

@section('actions')
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createContent"><i class="ti ti-plus me-1"></i>New range</button>
@endsection

@section('content')
<div class="card">
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead><tr><th>Weeks</th><th>Baby size</th><th>Weight</th><th>Length</th><th>Tip</th><th>Image</th><th>Status</th><th class="w-1"></th></tr></thead>
            <tbody>
            @forelse ($contents as $content)
                <tr>
                    <td class="fw-medium">{{ $content->week_from }}–{{ $content->week_to }}</td>
                    <td>{{ $content->size_en }}<div class="small" dir="rtl">{{ $content->size_ar }}</div></td>
                    <td>{{ $content->weight_en }}</td>
                    <td>{{ $content->length_en }}</td>
                    <td class="small text-secondary">{{ \Illuminate\Support\Str::limit($content->tip_en, 60) ?: '—' }}</td>
                    <td><x-admin.thumb :src="$content->image ? asset($content->image) : null" /></td>
                    <td><x-admin.status :status="$content->is_active ? 'active' : 'inactive'" /></td>
                    <td class="text-nowrap">
                        <button class="btn btn-sm btn-ghost-primary btn-icon" data-bs-toggle="modal" data-bs-target="#editContent{{ $content->id }}" title="Edit" aria-label="Edit"><i class="ti ti-edit"></i></button>
                        <x-admin.delete :action="route('dashboard.health_content.pregnancy.destroy', $content)" />
                    </td>
                </tr>
            @empty
                <tr><td colspan="8"><x-admin.empty icon="baby-carriage" title="No content" text="The app shows “not available” for the baby size." /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

@push('modals')
    @foreach ([null, ...$contents] as $content)
        @php $editing = $content !== null; @endphp
        <x-admin.modal :id="$editing ? 'editContent'.$content->id : 'createContent'" :title="$editing ? 'Weeks '.$content->week_from.'–'.$content->week_to : 'New week range'"
                       :action="$editing ? route('dashboard.health_content.pregnancy.update', $content) : route('dashboard.health_content.pregnancy.store')"
                       :method="$editing ? 'PUT' : 'POST'" size="lg" :files="true">
            <div class="row">
                <x-admin.input col="col-md-6 mb-3" type="number" name="week_from" label="From week" :value="$content?->week_from" min="1" max="42" required />
                <x-admin.input col="col-md-6 mb-3" type="number" name="week_to" label="To week" :value="$content?->week_to" min="1" max="42" required />
                @foreach (['size' => 'Baby size', 'weight' => 'Weight', 'length' => 'Length'] as $field => $label)
                    <x-admin.input col="col-md-6 mb-3" :name="$field.'_en'" :label="$label.' (English)'" :value="$content?->{$field.'_en'}" required />
                    <x-admin.input col="col-md-6 mb-3" :name="$field.'_ar'" :label="$label.' (Arabic)'" :value="$content?->{$field.'_ar'}" required dir="rtl" />
                @endforeach
                <x-admin.input col="col-md-6 mb-3" type="textarea" name="tip_en" label="Tip (English)" :value="$content?->tip_en" rows="3" />
                <x-admin.input col="col-md-6 mb-3" type="textarea" name="tip_ar" label="Tip (Arabic)" :value="$content?->tip_ar" rows="3" dir="rtl" />
                <x-admin.input col="col-md-6 mb-3" type="file" name="image" label="Illustration" accept="image/*" />
            </div>
            <label class="form-check form-switch mb-0">
                <input type="hidden" name="is_active" value="0">
                <input class="form-check-input" type="checkbox" name="is_active" value="1" @checked($content?->is_active ?? true)>
                <span class="form-check-label">Active</span>
            </label>
        </x-admin.modal>
    @endforeach
@endpush
