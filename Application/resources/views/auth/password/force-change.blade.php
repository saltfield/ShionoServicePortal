@extends('layouts.guest')

@section('title', 'パスワード変更')

@section('content')
    <div class="ssp-login-panel">
        <div class="ssp-login-panel__accent" aria-hidden="true"></div>
        <div class="ssp-login-panel__body">
            <div class="ssp-login-brand">SSP</div>
            <h1 class="ssp-login-title">パスワードの変更</h1>
            <p class="ssp-login-lead">セキュリティのため、新しいパスワードの設定が必要です。</p>

            @if ($errors->any())
                <div class="alert alert-danger py-2">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ $updateRoute }}">
                @csrf
                <div class="mb-3">
                    <label for="password" class="form-label">新しいパスワード</label>
                    <input type="password" name="password" id="password" class="form-control" required autocomplete="new-password">
                </div>
                <div class="mb-3">
                    <label for="password_confirmation" class="form-label">新しいパスワード（確認）</label>
                    <input type="password" name="password_confirmation" id="password_confirmation" class="form-control" required autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-primary w-100">変更して続行</button>
            </form>

            <form method="POST" action="{{ $logoutRoute }}" class="mt-3 text-center">
                @csrf
                <button type="submit" class="btn btn-link btn-sm">ログアウト</button>
            </form>
        </div>
    </div>
@endsection
