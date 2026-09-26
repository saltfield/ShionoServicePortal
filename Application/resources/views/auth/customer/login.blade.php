@extends('layouts.guest')

@section('title', 'カスタマーログイン')
@section('portal', 'customer')

@section('content')
    <div class="ssp-login-panel">
        <div class="ssp-login-panel__accent" aria-hidden="true"></div>
        <div class="ssp-login-panel__body">
            <div class="ssp-login-brand">SSP</div>
            <h1 class="ssp-login-title">カスタマーログイン</h1>
            <p class="ssp-login-lead">カスタマー専用のログイン画面です。CN の入力が必要です。</p>

            @if ($errors->any())
                <div class="alert alert-danger py-2">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('customer.login.store') }}">
                @csrf
                <div class="mb-3">
                    <label for="cn" class="form-label">CN</label>
                    <input type="text" name="cn" id="cn" value="{{ old('cn') }}"
                           class="form-control" required autofocus placeholder="CN202609001">
                </div>
                <div class="mb-3">
                    <label for="login_id" class="form-label">ログインID</label>
                    <input type="text" name="login_id" id="login_id" value="{{ old('login_id') }}"
                           class="form-control" required autocomplete="username">
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">パスワード</label>
                    <input type="password" name="password" id="password" class="form-control" required autocomplete="current-password">
                </div>
                <button type="submit" class="btn btn-primary w-100">ログイン</button>
            </form>
        </div>
    </div>
@endsection
