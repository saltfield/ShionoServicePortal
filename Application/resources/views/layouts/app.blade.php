<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name'))</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-light">
@php
    use App\Support\PortalNavigation;
    $guard = PortalNavigation::guard();
    $user = PortalNavigation::user();
    $area = trim($__env->yieldContent('area')) ?: PortalNavigation::areaLabel($guard);
    $dashboardRoute = $guard.'.dashboard';
    $logoutRoute = $guard.'.logout';
    $passwordRoute = $guard.'.password.edit';
    $twoFactorRoute = $guard.'.two-factor.settings';
    $menuItems = PortalNavigation::visibleMenu($guard, $user);
    $displayName = $user?->name ?: $user?->login_id ?: 'ユーザー';
@endphp
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            @if (\Illuminate\Support\Facades\Route::has($dashboardRoute))
                <a class="navbar-brand" href="{{ route($dashboardRoute) }}">{{ $area }}</a>
            @else
                <span class="navbar-brand mb-0 h1">{{ $area }}</span>
            @endif

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#portalNavbar" aria-controls="portalNavbar" aria-expanded="false" aria-label="メニュー">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="portalNavbar">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    @foreach ($menuItems as $item)
                        <li class="nav-item">
                            <a class="nav-link{{ request()->url() === $item['url'] ? ' active' : '' }}" href="{{ $item['url'] }}">{{ $item['label'] }}</a>
                        </li>
                    @endforeach
                </ul>

                @if ($user)
                    <ul class="navbar-nav ms-auto">
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="accountMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                {{ $displayName }}
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="accountMenu">
                                @if (\Illuminate\Support\Facades\Route::has($passwordRoute))
                                    <li><a class="dropdown-item" href="{{ route($passwordRoute) }}">パスワード変更</a></li>
                                @endif
                                @if (\Illuminate\Support\Facades\Route::has($twoFactorRoute))
                                    <li><a class="dropdown-item" href="{{ route($twoFactorRoute) }}">2FA設定</a></li>
                                @endif
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <form method="POST" action="{{ route($logoutRoute) }}">
                                        @csrf
                                        <button type="submit" class="dropdown-item">ログアウト</button>
                                    </form>
                                </li>
                            </ul>
                        </li>
                    </ul>
                @endif
            </div>
        </div>
    </nav>
    <main class="container py-4">
        @yield('content')
    </main>
    @stack('scripts')
    @livewireScripts
</body>
</html>
