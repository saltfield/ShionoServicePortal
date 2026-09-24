@extends('layouts.app')

@section('title', '契約一覧')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h3 mb-0">契約一覧</h1>
            <p class="text-muted small mb-0 mt-1">
                @if (($routePrefix ?? 'admin') === 'bp')
                    配下BPの全契約を表示します。特定カスタマーの契約はカスタマー詳細から確認できます。
                @else
                    全カスタマーの契約を表示します。特定カスタマーの契約はカスタマー詳細から確認できます。
                @endif
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route(($routePrefix ?? 'admin').'.contracts.create') }}" class="btn btn-primary btn-sm">オーダー作成</a>
            <a href="{{ route(($routePrefix ?? 'admin').'.dashboard') }}" class="btn btn-outline-secondary btn-sm">ダッシュボード</a>
        </div>
    </div>
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if (! empty($unreadMessagesFilter))
        <div class="alert alert-info py-2">
            未読オーダーメッセージがある契約のみ表示しています。
            <a href="{{ route(($routePrefix ?? 'admin').'.contracts.index', array_filter(['status' => $statusFilter ?? null])) }}" class="ms-2">すべて表示</a>
        </div>
    @endif

    @php
        $statusFilter = $statusFilter ?? null;
        $statusTabs = [
            '' => 'すべて',
            'draft' => 'オーダー作成中',
            'pending_price_approval' => '価格申請（未決裁）',
            'approved' => '承認済・手配中',
            'activated' => 'サービス提供開始',
            'cancelled' => '解約',
        ];
        $showAppliedAt = $statusFilter === 'pending_price_approval';
        $showActivatedAt = $statusFilter === 'activated';
        $colspan = 5 + ($showAppliedAt ? 1 : 0) + ($showActivatedAt ? 1 : 0);
    @endphp
    <ul class="nav nav-pills flex-wrap gap-1 mb-3">
        @foreach ($statusTabs as $value => $label)
            <li class="nav-item">
                <a
                    class="nav-link py-1 px-2{{ ($statusFilter ?? '') === $value ? ' active' : '' }}"
                    href="{{ route(($routePrefix ?? 'admin').'.contracts.index', $value === '' ? [] : ['status' => $value]) }}"
                >{{ $label }}</a>
            </li>
        @endforeach
    </ul>

    @if (in_array($statusFilter, ['pending_price_approval', 'approved'], true))
        <p class="small text-muted mb-2">古いものから上に表示しています。上から順に対応してください。</p>
    @endif

    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead>
                <tr>
                    <th>契約番号</th>
                    <th>カスタマー</th>
                    <th>拠点</th>
                    <th>管理BP</th>
                    <th>状態</th>
                    @if ($showAppliedAt)
                        <th>申請日時</th>
                    @endif
                    @if ($showActivatedAt)
                        <th>提供開始日</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($contracts as $contract)
                    <tr>
                        <td>
                            <a href="{{ route(($routePrefix ?? 'admin').'.contracts.show', array_merge(['contract' => $contract], ! empty($unreadMessagesFilter) ? ['tab' => 'messages'] : [])) }}">
                                <code>{{ $contract->code }}</code>
                            </a>
                        </td>
                        <td>{{ $contract->customer?->code }}</td>
                        <td>{{ $contract->site?->name }}</td>
                        <td>{{ $contract->owningBp?->code }}</td>
                        <td>{{ $contract->status->label() }}
                            @if ($contract->special_price_requested)
                                <span class="badge text-bg-danger ms-1">特価申請あり</span>
                            @endif
                            @if ($contract->status->value === 'activated' && $contract->billing_suspended)
                                <span class="badge text-bg-warning ms-1">請求停止</span>
                            @endif
                            @if ($contract->status->value === 'activated' && $contract->end_user_billing_disabled)
                                <span class="badge text-bg-secondary ms-1">EU請求無効</span>
                            @endif
                        </td>
                        @if ($showAppliedAt)
                            <td class="small text-nowrap">
                                {{ $contract->applied_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '—' }}
                            </td>
                        @endif
                        @if ($showActivatedAt)
                            <td class="small text-nowrap">
                                {{ $contract->activated_at?->timezone(config('app.timezone'))->format('Y-m-d') ?? '—' }}
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $colspan }}" class="text-muted">契約がありません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $contracts->links() }}
@endsection
