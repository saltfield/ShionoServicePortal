@extends('layouts.guest')

@section('title', '二段階認証')

@section('content')
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h1 class="h4 mb-3">二段階認証</h1>
            <p class="text-muted small mb-4">Google Authenticator の6桁コードを入力してください。</p>

            @if ($errors->any())
                <div class="alert alert-danger">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ $storeRoute }}">
                @csrf
                <div class="mb-3">
                    <label for="code" class="form-label">認証コード</label>
                    <input type="text" name="code" id="code" class="form-control" inputmode="numeric"
                           pattern="[0-9]*" maxlength="6" required autofocus autocomplete="one-time-code">
                </div>
                <button type="submit" class="btn btn-primary w-100">確認</button>
            </form>

            <div class="mt-3 text-center">
                <a href="{{ $loginRoute }}" class="small">ログイン画面に戻る</a>
            </div>
        </div>
    </div>
@endsection
