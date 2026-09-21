@extends('layouts.app')

@section('title', 'お知らせ編集')
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
        'announcement' => $announcement,
        'selectedDeliveryType' => $selectedDeliveryType,
        'selectedTargetIds' => $selectedTargetIds,
        'includeNewRegistrations' => $includeNewRegistrations,
        'publishedMode' => $publishedMode,
        'expiresMode' => $expiresMode,
        'publishedDate' => $publishedDate,
        'publishedTime' => $publishedTime,
        'expiresDate' => $expiresDate,
        'expiresTime' => $expiresTime,
    ])
@endsection
