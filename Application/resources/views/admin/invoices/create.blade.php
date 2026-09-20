@extends('layouts.app')

@section('title', '請求発行')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection

@section('content')
    <h1 class="h3 mb-3">請求発行</h1>
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.invoices.store') }}" class="card card-body" style="max-width:40rem">
        @csrf
        <div class="mb-3">
            <label class="form-label" for="contract_id">契約</label>
            <select name="contract_id" id="contract_id" class="form-select" required>
                <option value="">選択してください</option>
                @foreach ($contracts as $contract)
                    <option value="{{ $contract->id }}" @selected((int) old('contract_id') === $contract->id)>
                        {{ $contract->code }} / {{ $contract->customer?->name }}（{{ $contract->owningBp?->code }}）
                    </option>
                @endforeach
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label" for="billing_year_month">請求月（YYYYMM）</label>
            <input type="text" name="billing_year_month" id="billing_year_month" class="form-control" required pattern="\d{6}" value="{{ old('billing_year_month', $defaultMonth) }}">
        </div>
        <div class="mb-3">
            <label class="form-label" for="note">メモ</label>
            <textarea name="note" id="note" class="form-control" rows="2">{{ old('note') }}</textarea>
        </div>
        <p class="small text-muted">イニシャルは開通月のみ、ランニングは指定月に計上されます。</p>
        <button class="btn btn-primary" type="submit">発行する</button>
        <a href="{{ route(($routePrefix ?? 'admin').'.invoices.index') }}" class="btn btn-link">一覧へ</a>
    </form>
@endsection
