@extends('layouts.app')

@section('title', 'ユーザー作成')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection

@section('content')
    @php
        $prefix = $routePrefix ?? 'admin';
        $selectedCustomerId = (int) old('customer_id', $selectedCustomerId ?? 0) ?: null;
        $returnCustomerId = $returnCustomerId ?? null;
        $returnBpId = $returnBpId ?? null;
        $lockUserType = $lockUserType ?? false;
        $lockOrganization = $lockOrganization ?? false;
        $typeSwitchUrl = route($prefix.'.users.create');
        $typeSwitchQuery = [];
        if ($returnCustomerId) {
            $typeSwitchQuery['return_customer_id'] = $returnCustomerId;
        }
        if ($returnBpId) {
            $typeSwitchQuery['return_bp_id'] = $returnBpId;
        }
        if ($selectedCustomerId) {
            $typeSwitchQuery['customer_id'] = $selectedCustomerId;
        }
        if ($returnCustomerId) {
            $crumbs = [
                ['label' => 'カスタマー詳細', 'url' => route($prefix.'.customers.show', ['customer' => $returnCustomerId, 'tab' => 'users'])],
            ];
        } elseif ($returnBpId) {
            $crumbs = [
                ['label' => 'BP詳細', 'url' => route($prefix.'.business-partners.show', ['businessPartner' => $returnBpId, 'tab' => 'users'])],
            ];
        } else {
            $crumbs = [
                ['label' => 'ユーザー管理', 'url' => route($prefix.'.users.index')],
            ];
        }
    @endphp
    @include('partials.breadcrumb', ['crumbs' => $crumbs, 'current' => 'ユーザー作成'])
    <h1 class="ssp-page-title mb-3">ユーザー作成</h1>
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route($prefix.'.users.store') }}" class="card card-body" style="max-width:40rem">
        @csrf
        @if ($returnCustomerId)
            <input type="hidden" name="return_customer_id" value="{{ $returnCustomerId }}">
        @endif
        @if ($returnBpId)
            <input type="hidden" name="return_bp_id" value="{{ $returnBpId }}">
        @endif
        <div class="mb-3">
            <label class="form-label" for="user_type">種別</label>
            @if ($lockUserType)
                <input type="hidden" name="user_type" value="{{ $userType->value }}">
                <input type="text" id="user_type" class="form-control" value="{{ $userType->value === 'bp' ? 'BP' : ($userType->value === 'customer' ? 'カスタマー' : '管理者') }}" disabled>
            @else
            <select
                name="user_type"
                id="user_type"
                class="form-select"
                required
                onchange="location.href='{{ $typeSwitchUrl }}?type='+this.value+'{{ $typeSwitchQuery ? '&'.http_build_query($typeSwitchQuery) : '' }}'"
            >
                @if ($prefix === 'admin')
                    <option value="admin" @selected($userType->value === 'admin')>管理者</option>
                @endif
                <option value="bp" @selected($userType->value === 'bp')>BP</option>
                <option value="customer" @selected($userType->value === 'customer')>カスタマー</option>
            </select>
            @endif
        </div>
        <div class="mb-3">
            <label class="form-label" for="login_id">ログインID</label>
            <input type="text" name="login_id" id="login_id" class="form-control" value="{{ old('login_id') }}" required>
        </div>
        <div class="mb-3">
            <label class="form-label" for="name">氏名</label>
            <input type="text" name="name" id="name" class="form-control" value="{{ old('name') }}" required>
        </div>
        <div class="mb-3">
            <label class="form-label" for="email">メール</label>
            <input type="email" name="email" id="email" class="form-control" value="{{ old('email') }}">
        </div>
        @if ($userType->value === 'bp')
            <div class="mb-3">
                <label class="form-label" for="bp_id">所属BP</label>
                <select name="bp_id" id="bp_id" class="form-select" required @disabled($lockOrganization)>
                    @foreach ($businessPartners as $partner)
                        <option value="{{ $partner->id }}" @selected((int) old('bp_id', $businessPartners->count() === 1 ? $partner->id : 0) === $partner->id)>{{ $partner->code }} / {{ $partner->name }}</option>
                    @endforeach
                </select>
                @if ($lockOrganization && $businessPartners->count() === 1)
                    <input type="hidden" name="bp_id" value="{{ $businessPartners->first()->id }}">
                @endif
            </div>
        @endif
        @if ($userType->value === 'customer')
            <div class="mb-3">
                <label class="form-label" for="customer_id">所属カスタマー</label>
                <select name="customer_id" id="customer_id" class="form-select" required @disabled($selectedCustomerId && $returnCustomerId)>
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->id }}" @selected($selectedCustomerId === $customer->id || (int) old('customer_id') === $customer->id)>{{ $customer->code }} / {{ $customer->name }}</option>
                    @endforeach
                </select>
                @if ($selectedCustomerId && $returnCustomerId)
                    <input type="hidden" name="customer_id" value="{{ $selectedCustomerId }}">
                @endif
            </div>
        @endif
        <div class="mb-3">
            <label class="form-label" for="role_code">初期ロール</label>
            <select name="role_code" id="role_code" class="form-select" required>
                @foreach ($roles as $role)
                    @php
                        $code = is_object($role) ? $role->code : $role;
                        $label = is_object($role) ? ($role->name.'（'.$role->code.'）') : $role;
                        $perms = is_object($role) ? $role->permissions->pluck('code')->implode(', ') : '';
                    @endphp
                    <option value="{{ $code }}" data-permissions="{{ $perms }}" @selected(old('role_code') === $code)>{{ $label }}</option>
                @endforeach
            </select>
            <p class="form-text mb-0" id="create_role_permissions">作成後、編集画面で追加のロールを割り当てできます。</p>
        </div>
        <div class="mb-3">
            <label class="form-label" for="password">初期パスワード</label>
            <input type="password" name="password" id="password" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label" for="password_confirmation">初期パスワード（確認）</label>
            <input type="password" name="password_confirmation" id="password_confirmation" class="form-control" required>
        </div>
        <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="must_change_password" value="1" id="must_change_password" @checked(old('must_change_password', true))>
            <label class="form-check-label" for="must_change_password">初回ログインでパスワード変更を要求</label>
        </div>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" @checked(old('is_active', true))>
            <label class="form-check-label" for="is_active">有効</label>
        </div>
        <button class="btn btn-primary" type="submit">作成</button>
    </form>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const select = document.getElementById('role_code');
        const preview = document.getElementById('create_role_permissions');
        if (!select || !preview) return;
        const base = '作成後、編集画面で追加のロールを割り当てできます。';
        const sync = () => {
            const option = select.selectedOptions[0];
            const perms = option?.dataset?.permissions || '';
            preview.textContent = perms
                ? base + ' 含まれる権限: ' + perms
                : base;
        };
        select.addEventListener('change', sync);
        sync();
    });
</script>
@endpush
