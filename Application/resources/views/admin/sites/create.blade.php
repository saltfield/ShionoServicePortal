@extends('layouts.app')

@section('title', '拠点追加')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    @php $prefix = $routePrefix ?? 'admin'; @endphp
    @include('partials.breadcrumb', [
        'crumbs' => [
            ['label' => 'カスタマー', 'url' => route($prefix.'.customers.index', ['managing_bp_id' => $customer->managing_bp_id])],
            ['label' => 'カスタマー詳細', 'url' => route($prefix.'.customers.show', ['customer' => $customer, 'tab' => 'sites'])],
        ],
        'current' => '拠点追加',
    ])
    <h1 class="ssp-page-title mb-3">拠点追加（{{ $customer->code }}）</h1>
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route($prefix.'.sites.store', $customer) }}" class="card card-body" style="max-width:42rem">
        @csrf
        @include('admin.sites._form')
        <button class="btn btn-primary" type="submit">作成</button>
    </form>
@endsection

@push('scripts')
    @include('partials.postal-lookup-script')
@endpush
