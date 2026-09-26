@extends('layouts.app')

@section('title', 'ロール編集')
@section('area', '管理者')

@section('content')
    @include('partials.breadcrumb', [
        'crumbs' => [['label' => 'ロール管理', 'url' => route('admin.roles.index')]],
        'current' => 'ロール編集',
    ])
    <h1 class="ssp-page-title mb-3">ロール編集</h1>
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('admin.roles.update', $managedRole) }}" class="card card-body mb-4" style="max-width:48rem">
        @csrf
        @method('PUT')
        @include('admin.roles._form', [
            'managedRole' => $managedRole,
            'permissionGroups' => $permissionGroups,
            'scopes' => $scopes,
            'selectedPermissionCodes' => old('permission_codes', $selectedPermissionCodes),
            'creating' => false,
        ])
        <div class="mt-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary">保存</button>
            <a href="{{ route('admin.roles.index') }}" class="btn btn-outline-secondary">一覧へ</a>
        </div>
    </form>

    @if (! $managedRole->isBuiltin())
        <form method="POST" action="{{ route('admin.roles.destroy', $managedRole) }}"
              onsubmit="return confirm('このロールを削除しますか？');">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-outline-danger btn-sm">ロールを削除</button>
        </form>
    @endif
@endsection
