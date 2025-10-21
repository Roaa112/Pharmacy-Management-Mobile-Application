@extends('adminlte::page')

@section('title', 'Dashboard')

@section('content_header')
    <h1>Discounts</h1>
@stop

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="mb-0">Discounts</h3>
        <button class="btn btn-primary" data-toggle="modal" data-target="#createDiscountModal">Add Discount</button>
    </div>

    <div class="card-body table-responsive">
        @php
            $grouped = $discounts->groupBy('discount_type');
        @endphp

        @foreach (['fixed' => 'Fixed Amount Discounts', 'percent' => 'Percentage Discounts', 'buy_x_get_y' => 'Buy X Get Y', 'amount_gift' => 'Amount-Based Gifts'] as $type => $title)
            <h4 class="mt-4 mb-2">{{ $title }}</h4>
            <table class="table table-bordered mb-5">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Value</th>
                        <th>Min Amount</th>
                        <th>Min Quantity</th>
                        <th>Free Quantity</th>
                        <th>Targets</th>
                        <th>Gift</th>
                        <th>Valid From</th>
                        <th>To</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($grouped[$type] ?? [] as $discount)
                        <tr>
                            <td>{{ ucfirst($discount->discount_type) }}</td>
                            <td>
                                @if($discount->discount_value)
                                    {{ $discount->discount_type === 'percent' ? $discount->discount_value . '%' : 'EGP ' . $discount->discount_value }}
                                @else
                                    -
                                @endif
                            </td>
                            <td>{{ $discount->min_amount ?? '-' }}</td>
                            <td>{{ $discount->min_quantity ?? '-' }}</td>
                            <td>{{ $discount->free_quantity ?? '-' }}</td>

                            {{-- Targets --}}
                            <td>
                                @foreach($discount->targets->where('is_gift', false) as $target)
                                    <span class="badge badge-info">{{ ucfirst($target->target_type) }} #{{ $target->target_id }}</span><br>
                                @endforeach
                            </td>

                            {{-- Gifts --}}
                            <td>
                                @php
                                    $gift = $discount->targets->where('is_gift', true)->first();
                                @endphp
                                @if($gift)
                                    {{ ucfirst($gift->target_type) }} #{{ $gift->target_id }}
                                    @if($discount->gift_quantity)
                                        (x{{ $discount->gift_quantity }})
                                    @endif
                                @else
                                    -
                                @endif
                            </td>

                            {{-- Dates --}}
                            <td>{{ $discount->starts_at ? \Carbon\Carbon::parse($discount->starts_at)->format('Y-m-d H:i') : '-' }}</td>
                            <td>{{ $discount->ends_at ? \Carbon\Carbon::parse($discount->ends_at)->format('Y-m-d H:i') : '-' }}</td>

                            {{-- Actions --}}
                            <td class="d-flex gap-2">
                                <button class="btn btn-sm btn-warning" data-toggle="modal" data-target="#editDiscountModal-{{ $discount->id }}">Edit</button>
                                <form action="{{ route('dashboard.discounts.destroy', $discount->id) }}" method="POST" onsubmit="return confirm('Are you sure?')">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-danger">Delete</button>
                                </form>
                            </td>
                        </tr>

                        {{-- Include edit modal for each discount --}}
                        @include('dashboard.Discounts.modals.edit', ['discount' => $discount])
                    @empty
                        <tr><td colspan="10" class="text-center">No {{ $title }} found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        @endforeach
    </div>
</div>

{{-- Create Modal --}}
@include('dashboard.Discounts.modals.create')
@stop

@section('js')
    <script>
        console.log("Discount management loaded!");
    </script>
@stop
