@extends('layouts.app')

@section('title', '品目種別管理')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    @php $prefix = $routePrefix ?? 'admin'; @endphp
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">品目種別管理</h1>
    </div>
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <p class="text-muted small mb-3">
        オーダー作成時に品目を選択すると、種別に設定したメッセージを案内表示します。
        @if ($prefix === 'bp')
            ここでは自BP独自の種別のみ管理できます。標準種別は管理者が登録します。
        @endif
    </p>

    <form method="POST" action="{{ route($prefix.'.item-types.store') }}" class="card card-body mb-4">
        @csrf
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label" for="name">種別</label>
                <input type="text" name="name" id="name" class="form-control" required maxlength="255" value="{{ old('name') }}" placeholder="例: 光回線">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="message">メッセージ（任意）</label>
                <input type="text" name="message" id="message" class="form-control" maxlength="2000" value="{{ old('message') }}" placeholder="申込時に表示する案内">
            </div>
            <div class="col-auto">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" checked>
                    <label class="form-check-label" for="is_active">有効</label>
                </div>
            </div>
            <div class="col-auto">
                <button class="btn btn-primary" type="submit">追加</button>
            </div>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead>
                <tr>
                    <th style="width:12rem">種別</th>
                    <th>メッセージ</th>
                    <th style="width:5rem">状態</th>
                    <th style="width:5rem"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($types as $row)
                    <tr>
                        <form method="POST" action="{{ route($prefix.'.item-types.update', $row) }}">
                            @csrf
                            @method('PUT')
                            <td>
                                <input type="text" name="name" class="form-control form-control-sm" value="{{ $row->name }}" required maxlength="255">
                            </td>
                            <td>
                                <textarea name="message" class="form-control form-control-sm" rows="2" maxlength="2000">{{ $row->message }}</textarea>
                            </td>
                            <td>
                                <input type="checkbox" name="is_active" value="1" @checked($row->is_active)>
                            </td>
                            <td>
                                <button class="btn btn-outline-primary btn-sm" type="submit">更新</button>
                            </td>
                        </form>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-muted text-center">種別がありません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $types->links() }}
@endsection
