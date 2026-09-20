@extends('layouts.app')

@section('title', '監査ログ詳細')
@section('area', '管理者')
@section('logout_action', route('admin.logout'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">監査ログ詳細</h1>
        <a href="{{ route('admin.audit-logs.index') }}" class="btn btn-outline-secondary btn-sm">一覧へ</a>
    </div>

    <dl class="row">
        <dt class="col-sm-3">日時</dt><dd class="col-sm-9">{{ $log->created_at }}</dd>
        <dt class="col-sm-3">区分</dt><dd class="col-sm-9">{{ $log->category }}</dd>
        <dt class="col-sm-3">アクション</dt><dd class="col-sm-9"><code>{{ $log->action }}</code></dd>
        <dt class="col-sm-3">結果</dt><dd class="col-sm-9">{{ $log->result }}</dd>
        <dt class="col-sm-3">実行者</dt><dd class="col-sm-9">{{ $log->actor?->login_id ?? '-' }}</dd>
        <dt class="col-sm-3">対象ユーザー</dt><dd class="col-sm-9">{{ $log->targetUser?->login_id ?? '-' }}</dd>
        <dt class="col-sm-3">対象リソース</dt><dd class="col-sm-9">{{ $log->target_type ? $log->target_type.'#'.$log->target_id : '-' }}</dd>
        <dt class="col-sm-3">IP</dt><dd class="col-sm-9">{{ $log->ip_address ?? '-' }}</dd>
        <dt class="col-sm-3">User-Agent</dt><dd class="col-sm-9 small">{{ $log->user_agent ?? '-' }}</dd>
        <dt class="col-sm-3">メタ</dt>
        <dd class="col-sm-9"><pre class="bg-light p-2 small mb-0">{{ json_encode($log->meta, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></dd>
    </dl>
@endsection
