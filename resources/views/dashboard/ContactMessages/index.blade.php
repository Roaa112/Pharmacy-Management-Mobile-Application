@extends('admin.layout')

@section('title', 'Contact messages')
@section('pretitle', 'Operations')
@section('subtitle', 'Messages customers sent from the app.')

@section('content')
<div class="card">
    @if ($ContactMessages->isEmpty())
        <x-admin.empty icon="mail-off" title="No messages" />
    @else
        <div class="list-group list-group-flush">
            @foreach ($ContactMessages as $message)
                <div class="list-group-item">
                    <div class="row align-items-start g-3">
                        <div class="col-auto"><span class="avatar bg-azure-lt">{{ strtoupper(mb_substr($message->name ?: '?', 0, 1)) }}</span></div>
                        <div class="col min-w-0">
                            <div class="d-flex flex-wrap align-items-baseline gap-2">
                                <span class="fw-medium">{{ $message->name }}</span>
                                @if ($message->email)
                                    <a href="mailto:{{ $message->email }}" class="small text-secondary">{{ $message->email }}</a>
                                @endif
                                @if ($message->user_id)
                                    <a href="{{ route('dashboard.customers.show', $message->user_id) }}" class="small">View customer</a>
                                @endif
                                <span class="small text-secondary ms-auto">{{ $message->created_at->format('M j, Y · H:i') }}</span>
                            </div>
                            <p class="mt-1 mb-0 text-wrap" style="white-space: pre-line">{{ $message->message }}</p>
                        </div>
                        <div class="col-auto">
                            <x-admin.delete :action="route('dashboard.contact-messages.destroy', $message->id)" confirm="Delete this message?" />
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
        <x-admin.pagination :paginator="$ContactMessages" />
    @endif
</div>
@endsection
