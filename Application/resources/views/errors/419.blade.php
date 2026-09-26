@extends('layouts.guest')

@section('title', 'セッション期限切れ')

@php
    $path = request()->path();
    if (str_starts_with($path, 'admin')) {
        $loginUrl = route('admin.login');
        $areaLabel = '管理者';
        $portal = 'admin';
    } elseif (str_starts_with($path, 'bp')) {
        $loginUrl = route('bp.login');
        $areaLabel = 'BP';
        $portal = 'bp';
    } elseif (str_starts_with($path, 'customer')) {
        $loginUrl = route('customer.login');
        $areaLabel = 'カスタマー';
        $portal = 'customer';
    } else {
        $loginUrl = url('/');
        $areaLabel = null;
        $portal = 'admin';
    }
    $seconds = 10;
@endphp
@section('portal', $portal)

@section('content')
    <div class="ssp-login-panel">
        <div class="ssp-login-panel__accent" aria-hidden="true"></div>
        <div class="ssp-login-panel__body">
            <div class="ssp-login-brand">SSP</div>
            <h1 class="ssp-login-title">セッションの有効期限が切れました</h1>
            <p class="ssp-login-lead">
                長時間操作がなかったか、別のタブでログアウトした可能性があります。
                お手数ですが、再度ログインしてください。
            </p>
            <p class="mb-4">
                <span id="csrf-redirect-countdown">{{ $seconds }}</span> 秒後に
                @if ($areaLabel)
                    {{ $areaLabel }}ログイン画面
                @else
                    トップページ
                @endif
                へ移動します。
            </p>
            <a href="{{ $loginUrl }}" class="btn btn-primary w-100" id="csrf-redirect-link">今すぐログイン画面へ</a>
        </div>
    </div>
    <script>
        (function () {
            const seconds = {{ $seconds }};
            const loginUrl = @json($loginUrl);
            const countdownEl = document.getElementById('csrf-redirect-countdown');
            let remaining = seconds;
            const timer = window.setInterval(function () {
                remaining -= 1;
                if (countdownEl) {
                    countdownEl.textContent = String(Math.max(remaining, 0));
                }
                if (remaining <= 0) {
                    window.clearInterval(timer);
                    window.location.href = loginUrl;
                }
            }, 1000);
        })();
    </script>
@endsection
