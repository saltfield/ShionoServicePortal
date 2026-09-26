<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name'))</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
@php
    $portal = trim($__env->yieldContent('portal')) ?: 'admin';
@endphp
<body class="ssp-guest ssp-area-{{ $portal }}">
    <main class="container py-4 py-md-5">
        <div class="row justify-content-center">
            <div class="col-11 col-sm-9 col-md-6 col-lg-5 col-xl-4">
                @yield('content')
            </div>
        </div>
    </main>
</body>
</html>
