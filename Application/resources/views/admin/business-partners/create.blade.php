@extends('layouts.app')

@section('title', 'BP新規作成')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    @php $prefix = $routePrefix ?? 'admin'; @endphp
    @include('partials.breadcrumb', [
        'crumbs' => [['label' => 'BP管理', 'url' => route($prefix.'.business-partners.index')]],
        'current' => 'BP新規作成',
    ])
    <h1 class="h3 mb-3">BP新規作成</h1>

    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route($prefix.'.business-partners.store') }}" class="card card-body" style="max-width:40rem">
        @csrf
        <div class="mb-3">
            <label class="form-label" for="name">BP名</label>
            <input type="text" id="name" name="name" value="{{ old('name') }}" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label" for="parent_id">親BP（未選択でルート作成）</label>
            <select id="parent_id" name="parent_id" class="form-select" @if (! empty($requireParent)) required @endif>
                @unless (! empty($requireParent))
                    <option value="">（ルート）</option>
                @endunless
                @foreach ($parents as $parent)
                    <option value="{{ $parent->id }}" @selected((int) old('parent_id', $selectedParentId) === $parent->id)>
                        {{ $parent->code }} / {{ $parent->name }}（階層{{ $parent->depth }}）
                    </option>
                @endforeach
            </select>
            <div class="form-text">BPNは自動採番されます。</div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="two_factor_mode">2FAモード</label>
            <select id="two_factor_mode" name="two_factor_mode" class="form-select" required>
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
            <input class="form-check-input" type="checkbox" value="1" id="is_active" name="is_active" @checked(old('is_active', true))>
            <label class="form-check-label" for="is_active">有効</label>
        </div>
        <button type="submit" class="btn btn-primary">作成</button>
    </form>
@endsection

@push('scripts')
    @include('partials.postal-lookup-script')
@endpush
