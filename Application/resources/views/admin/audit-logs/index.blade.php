@extends('layouts.app')

@section('title', '監査ログ')
@section('area', '管理者')
@section('logout_action', route('admin.logout'))

@section('content')
    @php
        $filters = $filters ?? [
            'category' => '',
            'action' => '',
            'result' => '',
            'actor_type' => '',
            'org' => '',
        ];
    @endphp
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">監査ログ</h1>
        <a href="{{ route('admin.dashboard') }}" class="btn btn-outline-secondary btn-sm">ダッシュボード</a>
    </div>

    <form method="GET" class="row g-2 align-items-end mb-3">
        <div class="col-md-2">
            <label class="form-label small mb-1" for="category">区分</label>
            <input type="text" id="category" name="category" value="{{ $filters['category'] }}" class="form-control form-control-sm" placeholder="category">
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1" for="action">アクション</label>
            <input type="text" id="action" name="action" value="{{ $filters['action'] }}" class="form-control form-control-sm" placeholder="action">
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1" for="result">結果</label>
            <input type="text" id="result" name="result" value="{{ $filters['result'] }}" class="form-control form-control-sm" placeholder="result">
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1" for="actor_type">実行者区分</label>
            <select id="actor_type" name="actor_type" class="form-select form-select-sm">
                <option value="">すべて</option>
                <option value="admin" @selected($filters['actor_type'] === 'admin')>管理者</option>
                <option value="bp" @selected($filters['actor_type'] === 'bp')>BP</option>
                <option value="customer" @selected($filters['actor_type'] === 'customer')>カスタマー</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1" for="org">BP/カスタマー</label>
            <input type="text" id="org" name="org" value="{{ $filters['org'] }}" class="form-control form-control-sm" placeholder="BPN/CN・名称">
        </div>
        <div class="col-auto">
            <button class="btn btn-sm btn-primary" type="submit">フィルタ</button>
            <a href="{{ route('admin.audit-logs.index') }}" class="btn btn-sm btn-outline-secondary">クリア</a>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
            <thead>
            <tr>
                <th>日時</th>
                <th>区分</th>
                <th>アクション</th>
                <th>結果</th>
                <th>実行者区分</th>
                <th>組織</th>
                <th>実行者</th>
                <th>対象</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($logs as $log)
                @php
                    $actor = $log->actor;
                    $actorTypeLabel = match ($actor?->user_type?->value) {
                        'admin' => '管理者',
                        'bp' => 'BP',
                        'customer' => 'カスタマー',
                        default => '—',
                    };
                    $orgLabel = match ($actor?->user_type?->value) {
                        'bp' => $actor->businessPartner
                            ? $actor->businessPartner->code.' / '.$actor->businessPartner->name
                            : '—',
                        'customer' => $actor->customer
                            ? $actor->customer->code.' / '.$actor->customer->name
                            : '—',
                        default => '—',
                    };
                @endphp
                <tr>
                    <td class="small">{{ $log->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s') }}</td>
                    <td>{{ $log->category }}</td>
                    <td><code>{{ $log->action }}</code></td>
                    <td>{{ $log->result }}</td>
                    <td>{{ $actorTypeLabel }}</td>
                    <td class="small">{{ $orgLabel }}</td>
                    <td>{{ $actor?->login_id ?? '-' }}</td>
                    <td>{{ $log->targetUser?->login_id ?? ($log->target_type ? class_basename($log->target_type).'#'.$log->target_id : '-') }}</td>
                    <td><a href="{{ route('admin.audit-logs.show', $log) }}" class="btn btn-outline-secondary btn-sm">詳細</a></td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-muted text-center py-4">監査ログがありません。</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $logs->links() }}
    <p class="small text-muted mt-2 mb-0">監査ログは参照専用です（編集・削除不可）。</p>
@endsection
