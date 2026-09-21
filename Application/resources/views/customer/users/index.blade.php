@extends('layouts.app')

@section('title', 'ユーザー管理')
@section('area', 'カスタマー')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">ユーザー管理</h1>
        <a href="{{ route('customer.users.create') }}" class="btn btn-primary btn-sm">新規作成</a>
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
            <thead>
                <tr>
                    <th>ログインID</th>
                    <th>氏名</th>
                    <th>ロール</th>
                    <th>有効</th>
                    <th>2FA</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $user)
                    <tr>
                        <td class="font-monospace">{{ $user->login_id }}</td>
                        <td>{{ $user->name }}</td>
                        <td>{{ $user->roles->first()?->code ?? '—' }}</td>
                        <td>{{ $user->is_active ? '有効' : '無効' }}</td>
                        <td>{{ $user->two_factor_confirmed_at ? '設定済' : '未設定' }}</td>
                        <td class="text-end">
                            <a href="{{ route('customer.users.edit', $user) }}" class="btn btn-outline-secondary btn-sm">編集</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-muted">ユーザーがいません。</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $users->links() }}
@endsection
