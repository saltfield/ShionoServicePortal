@extends('layouts.app')

@section('title', 'ポリシー編集')
@section('area', '管理者')

@section('content')
    @include('partials.breadcrumb', [
        'crumbs' => [['label' => 'ABACポリシー管理', 'url' => route('admin.policies.index')]],
        'current' => 'ポリシー編集',
    ])
    <h1 class="ssp-page-title mb-3">ポリシー編集</h1>
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('admin.policies.update', $managedPolicy) }}" class="card card-body mb-4" style="max-width:56rem">
        @csrf
        @method('PUT')
        @include('admin.policies._form', [
            'managedPolicy' => $managedPolicy,
            'actionOptions' => $actionOptions,
            'operators' => $operators,
            'commonAttributes' => $commonAttributes,
            'conditionRows' => $conditionRows,
            'creating' => false,
        ])
        <div class="mt-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary">保存</button>
            <a href="{{ route('admin.policies.index') }}" class="btn btn-outline-secondary">一覧へ</a>
        </div>
    </form>

    <form method="POST" action="{{ route('admin.policies.destroy', $managedPolicy) }}"
          onsubmit="return confirm('このポリシーを削除しますか？認可挙動に影響します。');">
        @csrf
        @method('DELETE')
        <button type="submit" class="btn btn-outline-danger btn-sm">ポリシーを削除</button>
    </form>
@endsection
