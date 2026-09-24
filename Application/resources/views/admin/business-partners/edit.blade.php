@extends('layouts.app')

@section('title', 'BP編集')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    @php $prefix = $routePrefix ?? 'admin'; @endphp
    @include('partials.breadcrumb', [
        'crumbs' => [
            ['label' => 'BP管理', 'url' => route($prefix.'.business-partners.index')],
            ['label' => 'BP詳細', 'url' => route($prefix.'.business-partners.show', $partner)],
        ],
        'current' => 'BP編集',
    ])
    <h1 class="h3 mb-3">BP編集</h1>

    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route($prefix.'.business-partners.update', $partner) }}" class="card card-body" style="max-width:40rem">
        @csrf
        @method('PUT')
        <div class="mb-3">
            <label class="form-label">BPN</label>
            <input type="text" class="form-control" value="{{ $partner->code }}" disabled>
        </div>
        <div class="mb-3">
            <label class="form-label" for="name">BP名</label>
            <input type="text" id="name" name="name" value="{{ old('name', $partner->name) }}" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label" for="two_factor_mode">2FAモード</label>
            <select id="two_factor_mode" name="two_factor_mode" class="form-select" required>
                @foreach ($modes as $mode)
                    <option value="{{ $mode->value }}" @selected(old('two_factor_mode', $partner->two_factor_mode?->value) === $mode->value)>{{ $mode->label() }}</option>
                @endforeach
            </select>
        </div>
        @include('partials.address-fields', ['model' => $partner, 'prefix' => ''])
        <div class="mb-3">
            <label class="form-label" for="email">メール</label>
            <input type="email" name="email" id="email" class="form-control" value="{{ old('email', $partner->email) }}">
        </div>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" value="1" id="is_active" name="is_active" @checked(old('is_active', $partner->is_active))>
            <label class="form-check-label" for="is_active">有効</label>
        </div>
        <button type="submit" class="btn btn-primary">保存</button>
    </form>
@endsection

@push('scripts')
    @include('partials.postal-lookup-script')
@endpush
