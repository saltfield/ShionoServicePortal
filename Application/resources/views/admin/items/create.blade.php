@extends('layouts.app')

@section('title', ($routePrefix ?? 'admin') === 'bp' ? '独自サービス作成' : '品目新規作成')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    @php
        $prefix = $routePrefix ?? 'admin';
        $title = $prefix === 'bp' ? '独自サービス作成' : '品目新規作成';
    @endphp
    @include('partials.breadcrumb', [
        'crumbs' => [['label' => '品目', 'url' => route($prefix.'.items.index')]],
        'current' => $title,
    ])
    <h1 class="ssp-page-title mb-3">{{ $title }}</h1>
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route($prefix.'.items.store') }}" class="card card-body" style="max-width:40rem">
        @csrf
        @include('admin.items._form', ['item' => null])
        <button class="btn btn-primary" type="submit">作成</button>
    </form>
@endsection
