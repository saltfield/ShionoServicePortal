@extends('layouts.app')

@section('title', '監査ログ')
@section('area', '管理者')
@section('logout_action', route('admin.logout'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">監査ログ</h1>
        <a href="{{ route('admin.dashboard') }}" class="btn btn-outline-secondary btn-sm">ダッシュボード</a>
    </div>

    <form method="GET" class="row g-2 mb-3">
        <div class="col-auto">
            <input type="text" name="category" value="{{ request('category') }}" class="form-control form-control-sm" placeholder="category">
        </div>
        <div class="col-auto">
            <input type="text" name="action" value="{{ request('action') }}" class="form-control form-control-sm" placeholder="action">
        </div>
        <div class="col-auto">
            <input type="text" name="result" value="{{ request('result') }}" class="form-control form-control-sm" placeholder="result">
        </div>
        <div class="col-auto">
            <button class="btn btn-sm btn-primary" type="submit">フィルタ</button>
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
                <th>実行者</th>
                <th>対象</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @foreach ($logs as $log)
                <tr>
                    <td class="small">{{ $log->created_at }}</td>
                    <td>{{ $log->category }}</td>
                    <td><code>{{ $log->action }}</code></td>
                    <td>{{ $log->result }}</td>
                    <td>{{ $log->actor?->login_id ?? '-' }}</td>
                    <td>{{ $log->targetUser?->login_id ?? ($log->target_type ? class_basename($log->target_type).'#'.$log->target_id : '-') }}</td>
                    <td><a href="{{ route('admin.audit-logs.show', $log) }}" class="btn btn-outline-secondary btn-sm">詳細</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    {{ $logs->links() }}
    <p class="small text-muted mt-2 mb-0">監査ログは参照専用です（編集・削除不可）。</p>
@endsection
