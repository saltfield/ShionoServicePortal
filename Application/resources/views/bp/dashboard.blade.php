@extends('layouts.app')

@section('title', 'BPダッシュボード')
@section('area', 'BP')

@section('content')
    @php
        $bp = auth('bp')->user()->businessPartner;
        $unreadContractMessageCount = $unreadContractMessageCount ?? 0;
        $needsContractAttention = ($awaitingApplicationCount + $pendingPriceApprovalCount + $serviceArrangementCount) > 0;
        $needsMessageAttention = $unreadContractMessageCount > 0;
    @endphp
    <h1 class="ssp-page-title">BPダッシュボード</h1>
    <p class="ssp-page-lead">契約・チケット・お知らせの状況を確認できます。</p>

    <div class="ssp-org-card">
        <div class="ssp-org-card__label">自組織</div>
        <dl class="row mb-0 small">
            <dt class="col-4 text-muted">BPN</dt>
            <dd class="col-8 mb-1"><code>{{ $bp?->code ?? '—' }}</code></dd>
            <dt class="col-4 text-muted">名称</dt>
            <dd class="col-8 mb-0">{{ $bp?->name ?? '—' }}</dd>
        </dl>
    </div>

    @if ($needsContractAttention || $needsMessageAttention)
        <div class="alert alert-warning" role="alert">
            対処が必要な項目があります。下記を確認して対応してください。
            @if ($needsMessageAttention)
                <div class="mt-1 small">未読のオーダーメッセージがあります。</div>
            @endif
        </div>
    @endif

    <h2 class="ssp-section-title">契約</h2>
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            @include('partials.dashboard.stat-card', [
                'url' => route('bp.contracts.index', ['status' => 'draft']),
                'label' => 'オーダー作成中',
                'count' => $awaitingApplicationCount,
                'attention' => $awaitingApplicationCount > 0,
            ])
        </div>
        <div class="col-md-4">
            @include('partials.dashboard.stat-card', [
                'url' => route('bp.contracts.index', ['status' => 'pending_price_approval']),
                'label' => '価格申請（未決裁）',
                'count' => $pendingPriceApprovalCount,
                'attention' => $pendingPriceApprovalCount > 0,
            ])
        </div>
        <div class="col-md-4">
            @include('partials.dashboard.stat-card', [
                'url' => route('bp.contracts.index', ['status' => 'approved']),
                'label' => '承認済・手配中',
                'count' => $serviceArrangementCount,
                'attention' => $serviceArrangementCount > 0,
            ])
        </div>
    </div>

    <h2 class="ssp-section-title">その他</h2>
    <div class="row g-3">
        <div class="col-md-4">
            @include('partials.dashboard.stat-card', [
                'url' => route('bp.contracts.index', ['unread_messages' => 1]),
                'label' => '未読オーダーメッセージ',
                'count' => $unreadContractMessageCount,
                'attention' => $unreadContractMessageCount > 0,
            ])
        </div>
        <div class="col-md-4">
            @include('partials.dashboard.stat-card', [
                'url' => route('bp.tickets.received'),
                'label' => '未読チケット',
                'count' => $unreadTicketCount,
                'attention' => $unreadTicketCount > 0,
            ])
        </div>
        <div class="col-md-4">
            @include('partials.dashboard.stat-card', [
                'url' => route('bp.announcements.index'),
                'label' => '未読お知らせ',
                'count' => $unreadAnnouncementCount,
                'attention' => $unreadAnnouncementCount > 0,
            ])
        </div>
    </div>
@endsection
