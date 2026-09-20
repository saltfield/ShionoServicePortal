<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-light">
    <main class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card shadow-sm">
                    <div class="card-body p-4 p-md-5">
                        <h1 class="h3 mb-3">{{ config('app.name') }}</h1>
                        <p class="text-muted mb-4">
                            契約管理システム（Phase 2 基盤構築中）
                        </p>
                        <ul class="list-group list-group-flush mb-4">
                            <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                <span>
                                    <span class="text-muted">管理者ログイン</span>
                                    <code class="ms-2">/admin/login</code>
                                </span>
                                <a href="{{ route('admin.login') }}" class="btn btn-sm btn-outline-primary">開く</a>
                            </li>
                            <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                <span>
                                    <span class="text-muted">BPログイン</span>
                                    <code class="ms-2">/bp/login</code>
                                </span>
                                <a href="{{ route('bp.login') }}" class="btn btn-sm btn-outline-primary">開く</a>
                            </li>
                            <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                <span>
                                    <span class="text-muted">カスタマーログイン</span>
                                    <code class="ms-2">/customer/login</code>
                                </span>
                                <a href="{{ route('customer.login') }}" class="btn btn-sm btn-outline-primary">開く</a>
                            </li>
                        </ul>
                        <p class="small text-muted mb-0">
                            Laravel {{ app()->version() }} / Livewire / Bootstrap 5 / Pest
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </main>
    @livewireScripts
</body>
</html>
