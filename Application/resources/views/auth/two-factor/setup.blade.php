@extends('layouts.guest')

@section('title', '二段階認証の設定')

@section('content')
    <div class="ssp-login-panel">
        <div class="ssp-login-panel__accent" aria-hidden="true"></div>
        <div class="ssp-login-panel__body">
            <div class="ssp-login-brand">SSP</div>
            <h1 class="ssp-login-title">二段階認証の設定</h1>
            <p class="ssp-login-lead">
                Google Authenticator などで QR コードを読み取り、表示された6桁コードで設定を完了してください。
            </p>

            <div class="text-center mb-3">{!! $qrSvg !!}</div>
            <p class="small text-muted text-center mb-3">手動入力用シークレット: <code>{{ $secret }}</code></p>

            @if ($errors->any())
                <div class="alert alert-danger py-2">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ $storeRoute }}">
                @csrf
                <div class="mb-3">
                    <label for="code" class="form-label">認証コード</label>
                    <input type="text" name="code" id="code" class="form-control" inputmode="numeric"
                           pattern="[0-9]*" maxlength="6" required autofocus autocomplete="one-time-code">
                </div>
                <button type="submit" class="btn btn-primary w-100">設定を完了する</button>
            </form>

            <div class="mt-3 text-center">
                <a href="{{ $loginRoute }}" class="small">ログイン画面に戻る</a>
            </div>
        </div>
    </div>
@endsection
