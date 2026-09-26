@extends('layouts.app')

@section('title', 'カスタマーダッシュボード')
@section('area', 'カスタマー')

@section('content')
    @php
        $customer = auth('customer')->user()->customer;
        $unreadContractMessageCount = $unreadContractMessageCount ?? 0;
        $needsAttention = ($unreadTicketCount + $unreadAnnouncementCount + $unreadContractMessageCount) > 0;
    @endphp
    <h1 class="ssp-page-title">カスタマーダッシュボード</h1>
    <p class="ssp-page-lead">契約・チケット・お知らせの状況を確認できます。</p>

    <div class="ssp-org-card">
        <div class="ssp-org-card__label">自組織</div>
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

    <h2 class="ssp-section-title">状況</h2>
    <div class="row g-3">
        <div class="col-md-4">
            @include('partials.dashboard.stat-card', [
                'url' => route('customer.contracts.index', ['unread_messages' => 1]),
                'label' => '未読オーダーメッセージ',
                'count' => $unreadContractMessageCount,
                'attention' => $unreadContractMessageCount > 0,
            ])
        </div>
        <div class="col-md-4">
            @include('partials.dashboard.stat-card', [
                'url' => route('customer.tickets.issued'),
                'label' => '未読チケット',
                'count' => $unreadTicketCount,
                'attention' => $unreadTicketCount > 0,
            ])
        </div>
        <div class="col-md-4">
            @include('partials.dashboard.stat-card', [
                'url' => route('customer.announcements.index'),
                'label' => '未読お知らせ',
                'count' => $unreadAnnouncementCount,
                'attention' => $unreadAnnouncementCount > 0,
            ])
        </div>
    </div>
@endsection
