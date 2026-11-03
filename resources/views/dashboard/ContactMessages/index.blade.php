@extends('admin.layout')

@php $tabs = ['' => 'All', 'open' => 'Awaiting reply', 'replied' => 'Replied']; @endphp

@section('title', 'Contact messages')
@section('pretitle', 'Operations')
@section('subtitle', 'Messages customers sent from the app. Your reply reaches the customer as a notification in the app, and they can answer back from the app.')

@section('content')
<div class="card">
    <div class="card-header">
        <ul class="nav nav-tabs card-header-tabs">
            @foreach ($tabs as $value => $label)
                <li class="nav-item">
                    <a href="{{ route('dashboard.contacts.index', array_filter(['status' => $value])) }}" class="nav-link {{ (string) request('status') === $value ? 'active' : '' }}">
                        {{ $label }}
                        @if ($value === 'open' && $openCount > 0)
                            <span class="badge bg-orange text-white ms-2">{{ $openCount }}</span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
    </div>

    @if ($ContactMessages->isEmpty())
        <x-admin.empty icon="mail-off" title="No messages" />
    @else
        <div class="list-group list-group-flush">
            @foreach ($ContactMessages as $message)
                @php $replyOpen = (int) old('reply_to') === $message->id; @endphp
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
                                @if ($message->status === \App\Models\ContactMessage::STATUS_OPEN)
                                    <span class="badge bg-orange-lt">Awaiting reply</span>
                                @else
                                    <span class="badge bg-green-lt">Replied</span>
                                @endif
                                <span class="small text-secondary ms-auto">{{ $message->created_at->format('M j, Y · H:i') }}</span>
                            </div>
                            <p class="mt-1 mb-0 text-wrap" style="white-space: pre-line">{{ $message->message }}</p>

                            @if ($message->replies->isNotEmpty())
                                <div class="mt-3 d-flex flex-column gap-2">
                                    @foreach ($message->replies as $reply)
                                        @if ($reply->from_customer)
                                            <div class="border rounded p-2 me-md-5">
                                                <div class="small text-secondary mb-1"><i class="ti ti-user me-1"></i>{{ $message->name }} · {{ $reply->created_at->format('M j, Y · H:i') }}</div>
                                                <div class="text-wrap" style="white-space: pre-line">{{ $reply->body }}</div>
                                            </div>
                                        @else
                                            <div class="rounded p-2 ms-md-5 bg-primary-lt">
                                                <div class="small text-secondary mb-1"><i class="ti ti-building-hospital me-1"></i>Pharmacy · {{ $reply->admin?->name ?? 'Former admin' }} · {{ $reply->created_at->format('M j, Y · H:i') }}</div>
                                                <div class="text-wrap text-body" style="white-space: pre-line">{{ $reply->body }}</div>
                                                <div class="small mt-1 d-flex flex-wrap align-items-center gap-2">
                                                    @if ($reply->sent_at === null)
                                                        <span class="text-secondary"><i class="ti ti-clock me-1"></i>Sending…</span>
                                                    @else
                                                        <span class="text-secondary"><i class="ti ti-device-mobile me-1"></i>In the app</span>
                                                        @if ($reply->push_devices > 0)
                                                            <span class="text-green"><i class="ti ti-bell-ringing me-1"></i>Push notification · {{ $reply->push_devices }} {{ \Illuminate\Support\Str::plural('device', $reply->push_devices) }}</span>
                                                        @elseif ($reply->push_devices === 0)
                                                            <span class="text-secondary" title="The customer has not allowed notifications on any device, or the push was rejected."><i class="ti ti-bell-off me-1"></i>No push (no device)</span>
                                                        @endif
                                                    @endif
                                                    @if ($reply->delivery_error)
                                                        <span class="text-danger" title="{{ $reply->delivery_error }}"><i class="ti ti-alert-triangle me-1"></i>{{ \Illuminate\Support\Str::limit($reply->delivery_error, 90) }}</span>
                                                    @endif
                                                    @if ($reply->canResend())
                                                        @can('contact_messages.reply')
                                                            <form action="{{ route('dashboard.contact-messages.resend', [$message->id, $reply->id]) }}" method="POST" class="d-inline">
                                                                @csrf
                                                                <button type="submit" class="btn btn-sm btn-ghost-primary py-0"><i class="ti ti-refresh me-1"></i>Resend</button>
                                                            </form>
                                                        @endcan
                                                    @endif
                                                </div>
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            @endif

                            @can('contact_messages.reply')
                                <div class="mt-2">
                                    <button type="button" class="btn btn-sm {{ $message->status === 'open' ? 'btn-primary' : 'btn-outline-primary' }} {{ $replyOpen ? 'd-none' : '' }}"
                                            data-bs-toggle="collapse" data-bs-target="#reply-{{ $message->id }}" aria-expanded="{{ $replyOpen ? 'true' : 'false' }}"
                                            onclick="this.classList.add('d-none'); setTimeout(() => document.querySelector('#reply-{{ $message->id }} textarea').focus(), 200)">
                                        <i class="ti ti-arrow-back-up me-1"></i>{{ $message->replies->contains('from_customer', false) ? 'Reply again' : 'Reply' }}
                                    </button>
                                    <form id="reply-{{ $message->id }}" action="{{ route('dashboard.contact-messages.reply', $message->id) }}" method="POST" class="collapse {{ $replyOpen ? 'show' : '' }}">
                                        @csrf
                                        <input type="hidden" name="reply_to" value="{{ $message->id }}">
                                        <textarea name="body" rows="3" maxlength="2000" required dir="auto"
                                                  class="form-control {{ $replyOpen && $errors->has('body') ? 'is-invalid' : '' }}"
                                                  placeholder="Write your reply to {{ $message->name }}…">{{ $replyOpen ? old('body') : '' }}</textarea>
                                        @if ($replyOpen && $errors->has('body'))
                                            <div class="invalid-feedback d-block">{{ $errors->first('body') }}</div>
                                        @endif
                                        <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                                            <button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-send me-1"></i>Send reply</button>
                                            <span class="small text-secondary">
                                                Sent to the customer's app as a notification.
                                            </span>
                                        </div>
                                    </form>
                                </div>
                            @endcan
                        </div>
                        <div class="col-auto">
                            <x-admin.delete :action="route('dashboard.contact-messages.destroy', $message->id)" confirm="Delete this conversation?" />
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
        <x-admin.pagination :paginator="$ContactMessages" />
    @endif
</div>
@endsection
