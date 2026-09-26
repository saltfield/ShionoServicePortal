@extends('layouts.app')

@section('title', '通知設定')
@section('area')
    {{ $guard === 'admin' ? '管理者' : ($guard === 'bp' ? 'BP' : 'カスタマー') }}
@endsection

@section('content')
    <h1 class="ssp-page-title mb-3">通知設定</h1>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <p class="text-muted mb-4">メール通知の受信可否を設定できます。未設定の種別は既定でオンです。</p>

    <form method="POST" action="{{ $updateRoute }}" style="max-width:36rem">
        @csrf

        @foreach ($types as $type)
            <div class="mb-3 form-check">
                <input
                    type="checkbox"
                    class="form-check-input"
                    id="pref_{{ $type->value }}"
                    name="preferences[{{ $type->value }}]"
                    value="1"
                    @checked($preferences[$type->value] ?? true)
                    @disabled(! $type->allowsOptOut())
                >
                <label class="form-check-label" for="pref_{{ $type->value }}">
                    <strong>{{ $type->label() }}</strong>
                    @unless ($type->allowsOptOut())
                        <span class="badge text-bg-secondary ms-1">必須</span>
                    @endunless
                </label>
                <div class="form-text">{{ $type->description() }}</div>
            </div>
        @endforeach

        <button type="submit" class="btn btn-primary">保存</button>
    </form>

    <div class="mt-4">
        <a href="{{ $dashboardRoute }}" class="btn btn-link px-0">ダッシュボードへ</a>
    </div>
@endsection
