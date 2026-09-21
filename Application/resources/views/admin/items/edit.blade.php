@extends('layouts.app')

@section('title', '品目編集')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    <h1 class="h3 mb-3">品目編集</h1>
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.items.update', $item) }}" class="card card-body" style="max-width:40rem">
        @csrf
        @method('PUT')
        @include('admin.items._form', ['item' => $item])
        <button class="btn btn-primary" type="submit">保存</button>
    </form>
@endsection
