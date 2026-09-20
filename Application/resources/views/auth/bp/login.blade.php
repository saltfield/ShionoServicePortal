@extends('layouts.guest')

@section('title', 'BPログイン')

@section('content')
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h1 class="h4 mb-3">BPログイン</h1>
            <p class="text-muted small mb-4">Business Partner 専用のログイン画面です。BPN の入力が必要です。</p>

            @if ($errors->any())
                <div class="alert alert-danger">
                    {{ $errors->first() }}
                </div>
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
