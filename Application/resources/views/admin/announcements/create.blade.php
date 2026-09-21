@extends('layouts.app')

@section('title', 'お知らせ登録')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    @include('admin.announcements._form', [
        'routePrefix' => $routePrefix ?? 'admin',
        'allowBroadcast' => $allowBroadcast ?? ($allowAll ?? false),
        'businessPartners' => $businessPartners,
        'customers' => $customers,
    ])
@endsection
