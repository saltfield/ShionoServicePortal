@extends('layouts.app')

@section('title', 'お知らせ公開')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    <h1 class="h3 mb-3">お知らせ公開</h1>
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.announcements.store') }}" class="card card-body" style="max-width:40rem">
        @csrf
        <div class="mb-3">
            <label class="form-label" for="title">タイトル</label>
            <input type="text" name="title" id="title" class="form-control" value="{{ old('title') }}" required>
        </div>
        <div class="mb-3">
            <label class="form-label" for="body">本文</label>
            <textarea name="body" id="body" class="form-control" rows="6" required>{{ old('body') }}</textarea>
        </div>
        <div class="mb-3">
            <label class="form-label" for="target_type">配信先種別</label>
            <select name="target_type" id="target_type" class="form-select" required>
                @if ($allowAll ?? false)
                    <option value="all" @selected(old('target_type') === 'all')>全体</option>
                @endif
                <option value="bp" @selected(old('target_type') === 'bp')>BP</option>
                <option value="customer" @selected(old('target_type', 'customer') === 'customer')>カスタマー</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label" for="target_id">配信先</label>
            <select name="target_id" id="target_id" class="form-select">
                <option value="">（全体の場合は不要）</option>
                <optgroup label="BP">
                    @foreach ($businessPartners as $bp)
                        <option value="{{ $bp->id }}" data-type="bp" @selected((string) old('target_id') === (string) $bp->id)>
                            {{ $bp->code }} / {{ $bp->name }}
                        </option>
                    @endforeach
                </optgroup>
                <optgroup label="カスタマー">
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->id }}" data-type="customer" @selected((string) old('target_id') === (string) $customer->id)>
                            {{ $customer->code }} / {{ $customer->name }}
                        </option>
                    @endforeach
                </optgroup>
            </select>
            <div class="form-text">種別が BP / カスタマーのときは対応する配信先を選択してください。</div>
        </div>
        <button class="btn btn-primary" type="submit">公開</button>
        <a href="{{ route(($routePrefix ?? 'admin').'.announcements.index') }}" class="btn btn-link">一覧へ</a>
    </form>
@endsection
