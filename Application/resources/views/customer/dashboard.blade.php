@extends('layouts.app')

@section('title', 'カスタマーダッシュボード')
@section('area', 'カスタマー')

@section('content')
    @php
        $customer = auth('customer')->user()->customer;
        $unreadContractMessageCount = $unreadContractMessageCount ?? 0;
        $needsAttention = ($unreadTicketCount + $unreadAnnouncementCount + $unreadContractMessageCount) > 0;
    @endphp
    <h1 class="h3 mb-3">カスタマーダッシュボード</h1>

    <div class="border rounded p-3 bg-white mb-4" style="max-width:28rem">
        <div class="small text-muted mb-2">自組織</div>
        <dl class="row mb-0 small">
            <dt class="col-4 text-muted">CN</dt>
            <dd class="col-8 mb-1"><code>{{ $customer?->code ?? '—' }}</code></dd>
            <dt class="col-4 text-muted">名称</dt>
            <dd class="col-8 mb-0">{{ $customer?->name ?? '—' }}</dd>
        </dl>
    </div>

    @if ($needsAttention)
        <div class="alert alert-warning" role="alert">
            対処が必要な項目があります。下記を確認して対応してください。
            @if ($unreadContractMessageCount > 0)
                <div class="mt-1 small">未読のオーダーメッセージがあります。</div>
            @endif
        </div>
    @endif

    <div class="row g-3">
        <div class="col-md-4">
            <a href="{{ route('customer.contracts.index', ['unread_messages' => 1]) }}" class="text-decoration-none text-dark">
                <div class="border rounded p-3 h-100 {{ $unreadContractMessageCount > 0 ? 'bg-warning-subtle border-warning' : 'bg-white' }}">
                    <div class="small text-muted">未読オーダーメッセージ</div>
                    <div class="mt-1">
                        <span class="badge {{ $unreadContractMessageCount > 0 ? 'text-bg-warning' : 'text-bg-secondary' }} fs-5">{{ $unreadContractMessageCount }}</span>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="{{ route('customer.tickets.issued') }}" class="text-decoration-none text-dark">
                <div class="border rounded p-3 h-100 {{ $unreadTicketCount > 0 ? 'bg-warning-subtle border-warning' : 'bg-white' }}">
                    <div class="small text-muted">未読チケット</div>
                    <div class="mt-1">
                        <span class="badge {{ $unreadTicketCount > 0 ? 'text-bg-warning' : 'text-bg-secondary' }} fs-5">{{ $unreadTicketCount }}</span>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="{{ route('customer.announcements.index') }}" class="text-decoration-none text-dark">
                <div class="border rounded p-3 h-100 {{ $unreadAnnouncementCount > 0 ? 'bg-warning-subtle border-warning' : 'bg-white' }}">
                    <div class="small text-muted">未読お知らせ</div>
                    <div class="mt-1">
                        <span class="badge {{ $unreadAnnouncementCount > 0 ? 'text-bg-warning' : 'text-bg-secondary' }} fs-5">{{ $unreadAnnouncementCount }}</span>
                    </div>
                </div>
            </a>
        </div>
    </div>
@endsection
