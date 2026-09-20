@extends('layouts.app')

@section('title', '問い合わせ作成')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : (($routePrefix ?? '') === 'customer' ? 'カスタマー' : 'BP') }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    <h1 class="h3 mb-3">問い合わせ作成</h1>
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.inquiries.store') }}" class="card card-body" style="max-width:40rem">
        @csrf
        <div class="mb-3">
            <label class="form-label" for="subject">件名</label>
            <input type="text" name="subject" id="subject" class="form-control" value="{{ old('subject') }}" required>
        </div>
        <div class="mb-3">
            <label class="form-label" for="body">本文</label>
            <textarea name="body" id="body" class="form-control" rows="5" required>{{ old('body') }}</textarea>
        </div>
        @if (($routePrefix ?? '') === 'admin')
            <div class="mb-3">
                <label class="form-label" for="customer_id">カスタマー（任意）</label>
                <select name="customer_id" id="customer_id" class="form-select">
                    <option value="">指定なし</option>
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->id }}" @selected((string) old('customer_id') === (string) $customer->id)>
                            {{ $customer->code }} / {{ $customer->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="owning_bp_id">対応BP（カスタマー未指定時）</label>
                <select name="owning_bp_id" id="owning_bp_id" class="form-select">
                    <option value="">指定なし</option>
                    @foreach ($businessPartners as $bp)
                        <option value="{{ $bp->id }}" @selected((string) old('owning_bp_id') === (string) $bp->id)>
                            {{ $bp->code }} / {{ $bp->name }}
                        </option>
                    @endforeach
                </select>
            </div>
        @elseif (($routePrefix ?? '') === 'bp')
            <div class="mb-3">
                <label class="form-label" for="customer_id">カスタマー（任意・未指定時は自BP宛て）</label>
                <select name="customer_id" id="customer_id" class="form-select">
                    <option value="">指定なし</option>
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->id }}" @selected((string) old('customer_id') === (string) $customer->id)>
                            {{ $customer->code }} / {{ $customer->name }}
                        </option>
                    @endforeach
                </select>
            </div>
        @endif
        <button class="btn btn-primary" type="submit">作成</button>
        <a href="{{ route(($routePrefix ?? 'admin').'.inquiries.index') }}" class="btn btn-link">一覧へ</a>
    </form>
@endsection
