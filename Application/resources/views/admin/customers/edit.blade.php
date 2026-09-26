@extends('layouts.app')

@section('title', 'カスタマー編集')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    @php $prefix = $routePrefix ?? 'admin'; @endphp
    @include('partials.breadcrumb', [
        'crumbs' => [
            ['label' => 'カスタマー', 'url' => route($prefix.'.customers.index', ['managing_bp_id' => $customer->managing_bp_id])],
            ['label' => 'カスタマー詳細', 'url' => route($prefix.'.customers.show', $customer)],
        ],
        'current' => 'カスタマー編集',
    ])
    <h1 class="ssp-page-title mb-3">カスタマー編集</h1>
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.customers.update', $customer) }}" class="card card-body" style="max-width:40rem">
        @csrf
        @method('PUT')
        <div class="mb-3">
            <label class="form-label">CN</label>
            <input type="text" class="form-control" value="{{ $customer->code }}" disabled>
        </div>
        <div class="mb-3">
            <label class="form-label" for="name">カスタマー名</label>
            <input type="text" name="name" id="name" class="form-control" value="{{ old('name', $customer->name) }}" required>
        </div>
        <div class="mb-3">
            <label class="form-label" for="entity_type">区分</label>
            <select name="entity_type" id="entity_type" class="form-select" required>
                @foreach ($entityTypes as $type)
                    <option value="{{ $type->value }}" @selected(old('entity_type', $customer->entity_type?->value) === $type->value)>{{ $type->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label" for="two_factor_mode">2FAモード</label>
            <select name="two_factor_mode" id="two_factor_mode" class="form-select" required>
                @foreach ($modes as $mode)
                    <option value="{{ $mode->value }}" @selected(old('two_factor_mode', $customer->two_factor_mode?->value) === $mode->value)>{{ $mode->label() }}</option>
                @endforeach
            </select>
        </div>
        @include('partials.address-fields', ['model' => $customer, 'prefix' => ''])
        <div class="mb-3">
            <label class="form-label" for="email">メール</label>
            <input type="email" name="email" id="email" class="form-control" value="{{ old('email', $customer->email) }}">
        </div>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" @checked(old('is_active', $customer->is_active))>
            <label class="form-check-label" for="is_active">有効</label>
        </div>
        <button class="btn btn-primary" type="submit">保存</button>
    </form>
@endsection

@push('scripts')
    @include('partials.postal-lookup-script')
@endpush
