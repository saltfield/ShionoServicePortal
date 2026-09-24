<?php

use Illuminate\Http\Request;

it('renders 419 page with countdown redirect to matching login', function () {
    $request = Request::create('/admin/contracts/1', 'POST');
    app()->instance('request', $request);

    $html = view('errors.419')->render();

    expect($html)
        ->toContain('セッションの有効期限が切れました')
        ->toContain('秒後に')
        ->toContain(route('admin.login'))
        ->toContain('csrf-redirect-countdown')
        ->toContain('10');
});

it('renders 419 page for bp path with bp login', function () {
    $request = Request::create('/bp/contracts', 'POST');
    app()->instance('request', $request);

    $html = view('errors.419')->render();

    expect($html)->toContain(route('bp.login'));
});
