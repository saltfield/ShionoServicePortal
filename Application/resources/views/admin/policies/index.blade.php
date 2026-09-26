@extends('layouts.app')

@section('title', 'ABACポリシー管理')
@section('area', '管理者')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="ssp-page-title mb-0">ABACポリシー管理</h1>
        <a href="{{ route('admin.policies.create') }}" class="btn btn-primary btn-sm">ポリシー作成</a>
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <p class="ssp-page-lead">
        RBAC（ロール）で「できること」を付与したうえで、対象・状況による許可／拒否を細かく制御します。
        明示的な deny は allow より優先されます。
    </p>

    <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
            <thead>
            <tr>
                <th>コード</th>
                <th>名前</th>
                <th>効果</th>
                <th>resource</th>
                <th>action</th>
                <th class="text-end">優先度</th>
                <th class="text-end">条件数</th>
                <th>状態</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($policies as $policy)
                <tr>
                    <td><code class="small">{{ $policy->code }}</code></td>
                    <td>{{ $policy->name }}</td>
                    <td>
                        @if ($policy->effect === 'deny')
                            <span class="badge text-bg-danger">deny</span>
                        @else
                            <span class="badge text-bg-success">allow</span>
                        @endif
                    </td>
                    <td><code class="small">{{ $policy->resource }}</code></td>
                    <td><code class="small">{{ $policy->action }}</code></td>
                    <td class="text-end">{{ $policy->priority }}</td>
                    <td class="text-end">{{ $policy->conditions_count }}</td>
                    <td>
                        @if ($policy->is_active)
                            <span class="badge text-bg-primary">有効</span>
                        @else
                            <span class="badge text-bg-secondary">無効</span>
                        @endif
                    </td>
                    <td class="text-end">
                        <a href="{{ route('admin.policies.edit', $policy) }}" class="btn btn-outline-secondary btn-sm">編集</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="text-muted">ポリシーがありません。</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
