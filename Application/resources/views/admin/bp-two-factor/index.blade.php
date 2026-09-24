@extends('layouts.app')

@section('title', '2FAモード管理')
@section('area', '管理者')
@section('logout_action', route('admin.logout'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">2FAモード管理</h1>
        <a href="{{ route('admin.dashboard') }}" class="btn btn-outline-secondary btn-sm">ダッシュボード</a>
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @php
        $isBpTab = $tab === 'bp';
        $isCustomerTab = $tab === 'customer';
    @endphp

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link @if ($isBpTab) active @endif" href="{{ route('admin.bp-two-factor.index', ['tab' => 'bp']) }}">BP</a>
        </li>
        <li class="nav-item">
            <a class="nav-link @if ($isCustomerTab) active @endif" href="{{ route('admin.bp-two-factor.index', ['tab' => 'customer']) }}">カスタマー</a>
        </li>
    </ul>

    @if ($isBpTab)
        <form method="GET" action="{{ route('admin.bp-two-factor.index') }}" class="row g-2 align-items-end mb-3">
            <input type="hidden" name="tab" value="bp">
            <div class="col-md-3">
                <label for="bpn" class="form-label small mb-1">BPN</label>
                <input type="text" id="bpn" name="bpn" value="{{ $filters['bpn'] }}" class="form-control form-control-sm" placeholder="例: BPN202609">
            </div>
            <div class="col-md-4">
                <label for="bp_name" class="form-label small mb-1">BP名</label>
                <input type="text" id="bp_name" name="bp_name" value="{{ $filters['bp_name'] }}" class="form-control form-control-sm" placeholder="部分一致">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm">検索</button>
                <a href="{{ route('admin.bp-two-factor.index', ['tab' => 'bp']) }}" class="btn btn-outline-secondary btn-sm">クリア</a>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead>
                <tr>
                    <th>BPN</th>
                    <th>BP名</th>
                    <th>階層</th>
                    <th>モード</th>
                    <th>更新</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($partners as $partner)
                    <tr>
                        <td><code>{{ $partner->code }}</code></td>
                        <td>{{ $partner->name }}</td>
                        <td>{{ $partner->depth }}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.bp-two-factor.update', $partner) }}" class="d-flex gap-2">
                                @csrf
                                @method('PUT')
                                <select name="two_factor_mode" class="form-select form-select-sm" style="width:auto">
                                    @foreach ($modes as $mode)
                                        <option value="{{ $mode->value }}" @selected($partner->two_factor_mode === $mode)>
                                            {{ $mode->label() }}
                                        </option>
                                    @endforeach
                                </select>
                                <button class="btn btn-primary btn-sm" type="submit">保存</button>
                            </form>
                        </td>
                        <td class="small text-muted">{{ $partner->updated_at }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-muted text-center py-4">該当するBPがありません。</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{ $partners->links() }}
    @else
        <form method="GET" action="{{ route('admin.bp-two-factor.index') }}" class="row g-2 align-items-end mb-3">
            <input type="hidden" name="tab" value="customer">
            <div class="col-md-4">
                <label for="managing_bp_id" class="form-label small mb-1">管理BP <span class="text-danger">*</span></label>
                <select id="managing_bp_id" name="managing_bp_id" class="form-select form-select-sm" required>
                    <option value="">選択してください</option>
                    @foreach ($managingPartners as $partner)
                        <option value="{{ $partner->id }}" @selected((int) $filters['managing_bp_id'] === $partner->id)>
                            {{ $partner->code }} / {{ $partner->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label for="cn" class="form-label small mb-1">CN</label>
                <input type="text" id="cn" name="cn" value="{{ $filters['cn'] }}" class="form-control form-control-sm" placeholder="例: CN202609" @disabled(! $filters['managing_bp_id'])>
            </div>
            <div class="col-md-3">
                <label for="cn_name" class="form-label small mb-1">カスタマー名</label>
                <input type="text" id="cn_name" name="cn_name" value="{{ $filters['cn_name'] }}" class="form-control form-control-sm" placeholder="部分一致" @disabled(! $filters['managing_bp_id'])>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm">表示</button>
                <a href="{{ route('admin.bp-two-factor.index', ['tab' => 'customer']) }}" class="btn btn-outline-secondary btn-sm">クリア</a>
            </div>
        </form>

        @unless ($filters['managing_bp_id'])
            <p class="text-muted small mb-0">管理BPを選択すると、配下のカスタマーの2FAモードを設定できます。</p>
        @else
            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle">
                    <thead>
                    <tr>
                        <th>CN</th>
                        <th>カスタマー名</th>
                        <th>管理BP名</th>
                        <th>モード</th>
                        <th>更新</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($customers as $customer)
                        <tr>
                            <td><code>{{ $customer->code }}</code></td>
                            <td>{{ $customer->name }}</td>
                            <td>{{ $customer->managingBp?->name ?? '-' }}</td>
                            <td>
                                <form method="POST" action="{{ route('admin.bp-two-factor.customers.update', $customer) }}" class="d-flex gap-2">
                                    @csrf
                                    @method('PUT')
                                    <select name="two_factor_mode" class="form-select form-select-sm" style="width:auto">
                                        @foreach ($modes as $mode)
                                            <option value="{{ $mode->value }}" @selected($customer->two_factor_mode === $mode)>
                                                {{ $mode->label() }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <button class="btn btn-primary btn-sm" type="submit">保存</button>
                                </form>
                            </td>
                            <td class="small text-muted">{{ $customer->updated_at }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-muted text-center py-4">該当するカスタマーがありません。</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            {{ $customers->links() }}
        @endunless
    @endif
@endsection
