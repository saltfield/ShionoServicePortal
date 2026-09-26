@extends('layouts.app')

@section('title', 'カスタマー新規作成')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    @php
        $prefix = $routePrefix ?? 'admin';
        $managingBpId = old('managing_bp_id', $selectedManagingBpId ?? null);
        $listUrl = route($prefix.'.customers.index', array_filter(['managing_bp_id' => $managingBpId]));
    @endphp
    @include('partials.breadcrumb', [
        'crumbs' => [['label' => 'カスタマー', 'url' => $listUrl]],
        'current' => 'カスタマー新規作成',
    ])
    <h1 class="ssp-page-title mb-3">カスタマー新規作成</h1>
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.customers.store') }}" class="card card-body" style="max-width:40rem">
        @csrf
        <div class="mb-3">
            <label class="form-label" for="managing_bp_id">管理BP</label>
            <select id="managing_bp_id" name="managing_bp_id" class="form-select" required>
                <option value="">選択してください</option>
                @foreach ($managingPartners as $partner)
                    <option value="{{ $partner->id }}" @selected((int) old('managing_bp_id', $selectedManagingBpId) === $partner->id)>
                        {{ $partner->code }} / {{ $partner->name }}
                    </option>
                @endforeach
            </select>
            <div class="form-text">CNは自動採番されます。</div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="name">カスタマー名</label>
            <input type="text" name="name" id="name" class="form-control" value="{{ old('name') }}" required>
        </div>
        <div class="mb-3">
            <label class="form-label" for="entity_type">区分</label>
            <select name="entity_type" id="entity_type" class="form-select" required>
                @foreach ($entityTypes as $type)
                    <option value="{{ $type->value }}" @selected(old('entity_type', 'corporate') === $type->value)>{{ $type->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label" for="two_factor_mode">2FAモード</label>
            <select name="two_factor_mode" id="two_factor_mode" class="form-select" required>
                @foreach ($modes as $mode)
                    <option value="{{ $mode->value }}" @selected(old('two_factor_mode', 'optional') === $mode->value)>{{ $mode->label() }}</option>
                @endforeach
            </select>
        </div>
        @include('partials.address-fields', ['model' => null, 'prefix' => ''])
        <div class="mb-3">
            <label class="form-label" for="email">メール</label>
            <input type="email" name="email" id="email" class="form-control" value="{{ old('email') }}">
        </div>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" @checked(old('is_active', true))>
            <label class="form-check-label" for="is_active">有効</label>
        </div>
        <button class="btn btn-primary" type="submit">作成</button>
    </form>
@endsection

@push('scripts')
    @include('partials.postal-lookup-script')
@endpush
