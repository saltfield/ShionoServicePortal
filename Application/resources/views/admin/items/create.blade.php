@extends('layouts.app')

@section('title', '品目新規作成')
@section('area', '管理者')
@section('logout_action', route('admin.logout'))

@section('content')
    <h1 class="h3 mb-3">品目新規作成</h1>
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route('admin.items.store') }}" class="card card-body" style="max-width:40rem">
        @csrf
        @include('admin.items._form', ['item' => null])
        <button class="btn btn-primary" type="submit">作成</button>
    </form>
@endsection
