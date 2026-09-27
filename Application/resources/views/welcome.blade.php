<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="ssp-guest ssp-area-admin">
    <main class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-6">
                <div class="ssp-login-panel">
                    <div class="ssp-login-panel__accent" aria-hidden="true"></div>
                    <div class="ssp-login-panel__body">
                        <div class="ssp-login-brand">SSP</div>
                        <h1 class="ssp-login-title">ポータル入口</h1>
                        <p class="ssp-login-lead">利用するエリアのログイン画面を選んでください。</p>
                        <ul class="list-group list-group-flush mb-0">
                            <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                <span class="text-muted">BP</span>
                                <a href="{{ route('bp.login') }}" class="btn btn-sm btn-outline-primary">開く</a>
                            </li>
                            <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                <span class="text-muted">カスタマー</span>
                                <a href="{{ route('customer.login') }}" class="btn btn-sm btn-outline-primary">開く</a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </main>
    @livewireScripts
</body>
</html>
