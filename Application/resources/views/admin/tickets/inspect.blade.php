@extends('layouts.app')

@section('title', 'チケット確認')
@section('area', '管理者')
@section('logout_action', route('admin.logout'))

@section('content')
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small mb-2">
            @if (($backUrl ?? null) && ($backLabel ?? null))
                <li class="breadcrumb-item"><a href="{{ $backUrl }}">{{ $backLabel }}</a></li>
            @else
                <li class="breadcrumb-item"><a href="{{ route('admin.tickets.received') }}">受領チケット</a></li>
            @endif
            <li class="breadcrumb-item active" aria-current="page">チケット確認</li>
        </ol>
    </nav>
    <div class="mb-3">
        <div class="small text-muted font-monospace mb-1">{{ $inquiry->code }}</div>
        <h1 class="h3 mb-0">{{ $inquiry->subject }}</h1>
        <p class="small text-muted mb-0 mt-1">閲覧のみ（ステータス変更・返信はできません）</p>
    </div>

    <dl class="row mb-3">
        <dt class="col-sm-3">状態</dt><dd class="col-sm-9">{{ $inquiry->status->label() }}</dd>
        <dt class="col-sm-3">公開範囲</dt><dd class="col-sm-9">{{ $inquiry->visibility->label() }}</dd>
        <dt class="col-sm-3">カスタマー</dt><dd class="col-sm-9">{{ $inquiry->customer ? $inquiry->customer->code.' / '.$inquiry->customer->name : '—' }}</dd>
        <dt class="col-sm-3">対応先</dt>
        <dd class="col-sm-9">
            @if ($inquiry->assignee_type?->value === 'admin')
                管理者
            @else
                {{ $inquiry->assigneeBp?->code }} / {{ $inquiry->assigneeBp?->name }}
            @endif
        </dd>
        <dt class="col-sm-3">発行BP</dt><dd class="col-sm-9">{{ $inquiry->issuerBp ? $inquiry->issuerBp->code.' / '.$inquiry->issuerBp->name : '—' }}</dd>
        <dt class="col-sm-3">起票者</dt><dd class="col-sm-9">{{ $inquiry->openedBy?->name ?: $inquiry->openedBy?->login_id }}</dd>
    </dl>

    <h2 class="h5 mb-3">メッセージ</h2>
    <livewire:inquiry-chat
        :inquiry-id="$inquiry->id"
        route-prefix="admin"
        :read-only="true"
        :key="'inquiry-inspect-'.$inquiry->id"
    />
@endsection
