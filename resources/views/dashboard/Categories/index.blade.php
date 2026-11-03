@extends('admin.layout')

@section('title', 'Categories')
@section('pretitle', 'Catalog')
@section('subtitle', 'Top-level categories and their subcategories, as shown in the app.')

@section('actions')
    <x-admin.data-transfer :entities="['categories']" />
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createCategory"><i class="ti ti-plus me-1"></i>New category</button>
@endsection

@section('content')
<div class="card">
    @if ($categories->isEmpty())
        <x-admin.empty icon="category" title="No categories yet" text="Create a category, then add subcategories under it." />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Category</th><th>Arabic name</th><th>Subcategories</th><th class="w-1"></th></tr></thead>
                <tbody>
                @foreach ($categories as $category)
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-3">
                                <x-admin.thumb :src="$category->image ? asset($category->image) : null" :alt="$category->name_en" />
                                <span class="fw-medium">{{ $category->name_en }}</span>
                            </div>
                        </td>
                        <td dir="rtl" class="text-end text-md-start">{{ $category->name_ar }}</td>
                        <td><span class="badge bg-secondary-lt">{{ $category->subcategories->count() }}</span></td>
                        <td>
                            <div class="btn-list flex-nowrap">
                                <button class="btn btn-sm btn-ghost-secondary" data-bs-toggle="modal" data-bs-target="#addSub{{ $category->id }}"><i class="ti ti-plus me-1"></i>Subcategory</button>
                                <button class="btn btn-sm btn-ghost-primary btn-icon" data-bs-toggle="modal" data-bs-target="#editCategory{{ $category->id }}" title="Edit" aria-label="Edit"><i class="ti ti-edit"></i></button>
                                <x-admin.delete :action="route('dashboard.categories.destroy', $category)" confirm="Delete this category and its subcategories?" />
                            </div>
                        </td>
                    </tr>
                    @foreach ($category->subcategories as $sub)
                        <tr class="bg-light">
                            <td>
                                <div class="d-flex align-items-center gap-3 ps-4">
                                    <i class="ti ti-corner-down-right text-secondary"></i>
                                    <x-admin.thumb :src="$sub->image ? asset($sub->image) : null" :alt="$sub->name_en" />
                                    <span>{{ $sub->name_en }}</span>
                                </div>
                            </td>
                            <td dir="rtl" class="text-end text-md-start">{{ $sub->name_ar }}</td>
                            <td></td>
                            <td>
                                <div class="btn-list flex-nowrap justify-content-end">
                                    <button class="btn btn-sm btn-ghost-primary btn-icon" data-bs-toggle="modal" data-bs-target="#editCategory{{ $sub->id }}" title="Edit" aria-label="Edit"><i class="ti ti-edit"></i></button>
                                    <x-admin.delete :action="route('dashboard.categories.destroy', $sub)" />
                                </div>
                            </td>
                        </tr>
                    @endforeach
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection

@push('modals')
    <x-admin.modal id="createCategory" title="New category" :action="route('dashboard.categories.store')" files submit="Create">
        <input type="hidden" name="parent_id" value="">
        <x-admin.input name="name_en" label="Name (English)" required />
        <x-admin.input name="name_ar" label="Name (Arabic)" required dir="rtl" />
        <x-admin.input type="file" name="image" label="Image" accept="image/*" />
    </x-admin.modal>

    @foreach ($categories as $category)
        <x-admin.modal :id="'addSub'.$category->id" :title="'New subcategory in '.$category->name_en" :action="route('dashboard.categories.storeSubcategory', $category->id)" files submit="Create">
            <input type="hidden" name="parent_id" value="{{ $category->id }}">
            <x-admin.input name="name_en" label="Name (English)" required />
            <x-admin.input name="name_ar" label="Name (Arabic)" required dir="rtl" />
            <x-admin.input type="file" name="image" label="Image" accept="image/*" />
        </x-admin.modal>

        @foreach (collect([$category])->merge($category->subcategories) as $item)
            <x-admin.modal :id="'editCategory'.$item->id" title="Edit category" :action="route('dashboard.categories.update', $item->id)" method="PUT" files>
                <input type="hidden" name="parent_id" value="{{ $item->parent_id }}">
                <x-admin.input name="name_en" label="Name (English)" :value="$item->name_en" required />
                <x-admin.input name="name_ar" label="Name (Arabic)" :value="$item->name_ar" required dir="rtl" />
                <x-admin.input type="file" name="image" label="Replace image" accept="image/*" hint="Leave empty to keep the current image." />
            </x-admin.modal>
        @endforeach
    @endforeach
@endpush
