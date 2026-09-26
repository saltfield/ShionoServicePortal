@extends('layouts.app')

@section('title', 'ポリシー作成')
@section('area', '管理者')

@section('content')
    @include('partials.breadcrumb', [
        'crumbs' => [['label' => 'ABACポリシー管理', 'url' => route('admin.policies.index')]],
        'current' => 'ポリシー作成',
    ])
    <h1 class="ssp-page-title mb-3">ポリシー作成</h1>
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('admin.policies.store') }}" class="card card-body" style="max-width:56rem">
        @csrf
        @include('admin.policies._form', [
            'managedPolicy' => null,
            'actionOptions' => $actionOptions,
            'operators' => $operators,
            'commonAttributes' => $commonAttributes,
            'conditionRows' => $conditionRows,
            'creating' => true,
        ])
        <div class="mt-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary">作成</button>
            <a href="{{ route('admin.policies.index') }}" class="btn btn-outline-secondary">キャンセル</a>
        </div>
    </form>
@endsection
