@extends('layouts.app')

@section('title', '拠点追加')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    <h1 class="h3 mb-3">拠点追加（{{ $customer->code }}）</h1>
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.sites.store', $customer) }}" class="card card-body" style="max-width:42rem">
        @csrf
        @include('admin.sites._form')
        <button class="btn btn-primary" type="submit">作成</button>
    </form>
@endsection

@push('scripts')
    @include('partials.postal-lookup-script')
@endpush
