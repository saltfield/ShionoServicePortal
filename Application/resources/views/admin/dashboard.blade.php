@extends('layouts.app')

@section('title', '管理者ダッシュボード')
@section('area', '管理者')

@section('content')
    @php
        $unreadContractMessageCount = $unreadContractMessageCount ?? 0;
        $needsContractAttention = ($awaitingApplicationCount + $pendingPriceApprovalCount + $serviceArrangementCount) > 0;
        $needsMessageAttention = $unreadContractMessageCount > 0;
    @endphp
    <h1 class="h3 mb-3">管理者ダッシュボード</h1>

    <div class="border rounded p-3 bg-white mb-4" style="max-width:28rem">
        <div class="small text-muted mb-1">自組織</div>
        <div class="fw-semibold">管理者画面</div>
    </div>

    @if ($needsContractAttention || $needsMessageAttention)
        <div class="alert alert-warning" role="alert">
            対処が必要な項目があります。下記を確認して対応してください。
            @if ($needsMessageAttention)
                <div class="mt-1 small">未読のオーダーメッセージがあります。</div>
            @endif
        </div>
    @endif

    <h2 class="h6 text-muted mb-2">契約</h2>
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <a href="{{ route('admin.contracts.index', ['status' => 'draft']) }}" class="text-decoration-none text-dark">
                <div class="border rounded p-3 h-100 {{ $awaitingApplicationCount > 0 ? 'bg-warning-subtle border-warning' : 'bg-white' }}">
                    <div class="small text-muted">オーダー作成中</div>
                    <div class="mt-1">
                        <span class="badge {{ $awaitingApplicationCount > 0 ? 'text-bg-warning' : 'text-bg-secondary' }} fs-5">{{ $awaitingApplicationCount }}</span>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="{{ route('admin.contracts.index', ['status' => 'pending_price_approval']) }}" class="text-decoration-none text-dark">
                <div class="border rounded p-3 h-100 {{ $pendingPriceApprovalCount > 0 ? 'bg-warning-subtle border-warning' : 'bg-white' }}">
                    <div class="small text-muted">価格申請（未決裁）</div>
                    <div class="mt-1">
                        <span class="badge {{ $pendingPriceApprovalCount > 0 ? 'text-bg-warning' : 'text-bg-secondary' }} fs-5">{{ $pendingPriceApprovalCount }}</span>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="{{ route('admin.contracts.index', ['status' => 'approved']) }}" class="text-decoration-none text-dark">
                <div class="border rounded p-3 h-100 {{ $serviceArrangementCount > 0 ? 'bg-warning-subtle border-warning' : 'bg-white' }}">
                    <div class="small text-muted">承認済・手配中</div>
                    <div class="mt-1">
                        <span class="badge {{ $serviceArrangementCount > 0 ? 'text-bg-warning' : 'text-bg-secondary' }} fs-5">{{ $serviceArrangementCount }}</span>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <h2 class="h6 text-muted mb-2">その他</h2>
    <div class="row g-3">
        <div class="col-md-4">
            <a href="{{ route('admin.contracts.index', ['unread_messages' => 1]) }}" class="text-decoration-none text-dark">
                <div class="border rounded p-3 h-100 {{ $unreadContractMessageCount > 0 ? 'bg-warning-subtle border-warning' : 'bg-white' }}">
                    <div class="small text-muted">未読オーダーメッセージ</div>
                    <div class="mt-1">
                        <span class="badge {{ $unreadContractMessageCount > 0 ? 'text-bg-warning' : 'text-bg-secondary' }} fs-5">{{ $unreadContractMessageCount }}</span>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="{{ route('admin.tickets.received') }}" class="text-decoration-none text-dark">
                <div class="border rounded p-3 h-100 {{ $unreadTicketCount > 0 ? 'bg-warning-subtle border-warning' : 'bg-white' }}">
                    <div class="small text-muted">未読チケット</div>
                    <div class="mt-1">
                        <span class="badge {{ $unreadTicketCount > 0 ? 'text-bg-warning' : 'text-bg-secondary' }} fs-5">{{ $unreadTicketCount }}</span>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="{{ route('admin.announcements.index') }}" class="text-decoration-none text-dark">
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
