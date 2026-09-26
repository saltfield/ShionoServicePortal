@extends('layouts.app')

@section('title', 'ロール管理')
@section('area', '管理者')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="ssp-page-title mb-0">ロール管理</h1>
        <a href="{{ route('admin.roles.create') }}" class="btn btn-primary btn-sm">ロール作成</a>
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <p class="ssp-page-lead">
        Azure IAM のロール定義に相当します。ユーザーへの割り当てはユーザー編集画面で行います。
        割当候補に出る場所はロールの<strong>スコープ</strong>で決まります（bp → BP画面、customer → カスタマー画面、system → 管理者）。
    </p>

    <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
            <thead>
            <tr>
                <th>コード</th>
                <th>名前</th>
                <th>スコープ</th>
                <th class="text-end">権限数</th>
                <th class="text-end">割当ユーザー</th>
                <th>種別</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @foreach ($roles as $role)
                <tr>
                    <td><code>{{ $role->code }}</code></td>
                    <td>{{ $role->name }}</td>
                    <td>{{ $role->scope }}</td>
                    <td class="text-end">{{ $role->permissions_count }}</td>
                    <td class="text-end">{{ $role->users_count }}</td>
                    <td>
                        @if ($role->isBuiltin())
                            <span class="badge text-bg-secondary">組み込み</span>
                        @else
                            <span class="badge text-bg-primary">カスタム</span>
                        @endif
                    </td>
                    <td class="text-end">
                        <a href="{{ route('admin.roles.edit', $role) }}" class="btn btn-outline-secondary btn-sm">編集</a>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endsection
