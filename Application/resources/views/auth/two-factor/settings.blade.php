@extends('layouts.app')

@section('title', '2FA設定')
@section('area')
    {{ $guard === 'admin' ? '管理者' : ($guard === 'bp' ? 'BP' : 'カスタマー') }}
@endsection

@section('content')
    <h1 class="ssp-page-title mb-3">二段階認証（2FA）設定</h1>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <div class="card card-body mb-4" style="max-width:36rem">
        <dl class="row mb-0">
            <dt class="col-sm-4">ポリシー</dt>
            <dd class="col-sm-8">
                @switch($mode->value)
                    @case('forced')
                        必須
                        @break
                    @case('disabled')
                        利用不可
                        @break
                    @default
                        任意
                @endswitch
            </dd>
            <dt class="col-sm-4">状態</dt>
            <dd class="col-sm-8">
                @if ($enabled)
                    <span class="badge text-bg-success">有効</span>
                @else
                    <span class="badge text-bg-secondary">未設定</span>
                @endif
            </dd>
        </dl>
    </div>

    @if ($mode->value === 'disabled')
        <p class="text-muted">組織の設定により、二段階認証は利用できません。</p>
    @elseif ($enabled)
        <p class="mb-3">ログイン時に認証アプリの6桁コードが必要です。</p>
        @if ($canDisable)
            <form method="POST" action="{{ $disableRoute }}" onsubmit="return confirm('二段階認証を無効化しますか？');">
                @csrf
                <button type="submit" class="btn btn-outline-danger btn-sm">2FAを無効化</button>
            </form>
        @else
            <p class="text-muted small">組織ポリシーが「必須」のため、無効化できません。</p>
        @endif
    @elseif ($canEnable)
        <div class="card card-body" style="max-width:36rem">
            <h2 class="h5 mb-3">認証アプリを登録</h2>
            <p class="text-muted small mb-3">
                Google Authenticator などで QR コードを読み取り、表示された6桁コードで有効化してください。
            </p>
            <div class="text-center mb-3">{!! $qrSvg !!}</div>
            <p class="small text-muted text-center mb-3">手動入力用シークレット: <code>{{ $secret }}</code></p>
            <form method="POST" action="{{ $enableRoute }}">
                @csrf
                <div class="mb-3">
                    <label for="code" class="form-label">認証コード</label>
                    <input type="text" name="code" id="code" class="form-control" inputmode="numeric"
                           pattern="[0-9]*" maxlength="6" required autocomplete="one-time-code">
                </div>
                <button type="submit" class="btn btn-primary">有効化する</button>
            </form>
        </div>
    @endif

    <div class="mt-4">
        <a href="{{ $dashboardRoute }}" class="btn btn-link px-0">ダッシュボードへ</a>
    </div>
@endsection
