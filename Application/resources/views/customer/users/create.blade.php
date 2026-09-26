@extends('layouts.app')

@section('title', 'ユーザー作成')
@section('area', 'カスタマー')

@section('content')
    @include('partials.breadcrumb', [
        'crumbs' => [['label' => 'ユーザー管理', 'url' => route('customer.users.index')]],
        'current' => 'ユーザー作成',
    ])
    <h1 class="ssp-page-title mb-3">ユーザー作成</h1>
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route('customer.users.store') }}" class="card card-body" style="max-width:40rem">
        @csrf
        <div class="mb-3">
            <label class="form-label">所属カスタマー</label>
            <input type="text" class="form-control" value="{{ $customer->code }} / {{ $customer->name }}" disabled>
        </div>
        <div class="mb-3">
            <label class="form-label" for="login_id">ログインID</label>
            <input type="text" name="login_id" id="login_id" class="form-control" value="{{ old('login_id') }}" required>
        </div>
        <div class="mb-3">
            <label class="form-label" for="name">氏名</label>
            <input type="text" name="name" id="name" class="form-control" value="{{ old('name') }}" required>
        </div>
        <div class="mb-3">
            <label class="form-label" for="email">メール</label>
            <input type="email" name="email" id="email" class="form-control" value="{{ old('email') }}">
        </div>
        <div class="mb-3">
            <label class="form-label" for="role_code">初期ロール</label>
            <select name="role_code" id="role_code" class="form-select" required>
                @foreach ($roles as $role)
                    @php
                        $code = is_object($role) ? $role->code : $role;
                        $label = is_object($role) ? ($role->name.'（'.$role->code.'）') : $role;
                    @endphp
                    <option value="{{ $code }}" @selected(old('role_code', 'customer_member') === $code)>{{ $label }}</option>
                @endforeach
            </select>
            <p class="form-text mb-0">作成後、編集画面で追加のロールを割り当てできます。</p>
        </div>
        <div class="mb-3">
            <label class="form-label" for="password">初期パスワード</label>
            <input type="password" name="password" id="password" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label" for="password_confirmation">初期パスワード（確認）</label>
            <input type="password" name="password_confirmation" id="password_confirmation" class="form-control" required>
        </div>
        <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="must_change_password" value="1" id="must_change_password" @checked(old('must_change_password', true))>
            <label class="form-check-label" for="must_change_password">初回ログインでパスワード変更を要求</label>
        </div>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" @checked(old('is_active', true))>
            <label class="form-check-label" for="is_active">有効</label>
        </div>
        <button class="btn btn-primary" type="submit">作成</button>
    </form>
@endsection
