@extends('layouts.app')

@section('title', 'パスワード変更')
@section('area')
    {{ $guard === 'admin' ? '管理者' : ($guard === 'bp' ? 'BP' : 'カスタマー') }}
@endsection

@section('content')
    <h1 class="h3 mb-3">パスワード変更</h1>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ $updateRoute }}" class="card card-body" style="max-width:28rem">
        @csrf
        <div class="mb-3">
            <label for="current_password" class="form-label">現在のパスワード</label>
            <input type="password" name="current_password" id="current_password" class="form-control" required autocomplete="current-password">
        </div>
        <div class="mb-3">
            <label for="password" class="form-label">新しいパスワード</label>
            <input type="password" name="password" id="password" class="form-control" required autocomplete="new-password">
            <div class="form-text">8文字以上、英字と数字を含めてください。</div>
        </div>
        <div class="mb-3">
            <label for="password_confirmation" class="form-label">新しいパスワード（確認）</label>
            <input type="password" name="password_confirmation" id="password_confirmation" class="form-control" required autocomplete="new-password">
        </div>
        <button type="submit" class="btn btn-primary">変更する</button>
        <a href="{{ $dashboardRoute }}" class="btn btn-link">ダッシュボードへ</a>
    </form>
@endsection
