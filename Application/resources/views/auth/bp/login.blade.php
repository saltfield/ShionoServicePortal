@extends('layouts.guest')

@section('title', 'BPログイン')
@section('portal', 'bp')

@section('content')
    <div class="ssp-login-panel">
        <div class="ssp-login-panel__accent" aria-hidden="true"></div>
        <div class="ssp-login-panel__body">
            <div class="ssp-login-brand">SSP</div>
            <h1 class="ssp-login-title">BPログイン</h1>
            <p class="ssp-login-lead">Business Partner 専用のログイン画面です。BPN の入力が必要です。</p>

            @if ($errors->any())
                <div class="alert alert-danger py-2">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('bp.login.store') }}">
                @csrf
                <div class="mb-3">
                    <label for="bpn" class="form-label">BPN</label>
                    <input type="text" name="bpn" id="bpn" value="{{ old('bpn') }}"
                           class="form-control" required autofocus placeholder="BPN202609001">
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
