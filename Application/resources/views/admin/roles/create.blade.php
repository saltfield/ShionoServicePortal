@extends('layouts.app')

@section('title', 'ロール作成')
@section('area', '管理者')

@section('content')
    @include('partials.breadcrumb', [
        'crumbs' => [['label' => 'ロール管理', 'url' => route('admin.roles.index')]],
        'current' => 'ロール作成',
    ])
    <h1 class="ssp-page-title mb-3">ロール作成</h1>
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('admin.roles.store') }}" class="card card-body" style="max-width:48rem">
        @csrf
        @include('admin.roles._form', [
            'managedRole' => null,
            'permissionGroups' => $permissionGroups,
            'scopes' => $scopes,
            'selectedPermissionCodes' => old('permission_codes', []),
            'creating' => true,
        ])
        <div class="mt-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary">作成</button>
            <a href="{{ route('admin.roles.index') }}" class="btn btn-outline-secondary">キャンセル</a>
        </div>
    </form>
@endsection
