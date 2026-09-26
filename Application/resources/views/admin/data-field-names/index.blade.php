@extends('layouts.app')

@section('title', 'データ名称マスタ')
@section('area', '管理者')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="ssp-page-title mb-0">データ名称マスタ</h1>
    </div>
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <p class="text-muted small">テンプレートの <code>&#123;&#123;置換コード&#125;&#125;</code> に対応するキーを登録します。予約語は使用できません。</p>

    <form method="POST" action="{{ route('admin.data-field-names.store') }}" class="row g-2 align-items-end mb-4">
        @csrf
        <div class="col-md-4">
            <label class="form-label" for="name">名称</label>
            <input type="text" name="name" id="name" class="form-control" required placeholder="例: 回線番号" value="{{ old('name') }}">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="replace_code">置換コード</label>
            <input type="text" name="replace_code" id="replace_code" class="form-control" required pattern="[a-z][a-z0-9_]*" placeholder="例: line_id" value="{{ old('replace_code') }}">
        </div>
        <div class="col-auto">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" checked>
                <label class="form-check-label" for="is_active">有効</label>
            </div>
        </div>
        <div class="col-auto">
            <button class="btn btn-primary" type="submit">追加</button>
        </div>
    </form>

    <table class="table table-sm">
        <thead><tr><th>名称</th><th>置換コード</th><th>状態</th><th></th></tr></thead>
        <tbody>
            @foreach ($names as $row)
                <tr>
                    <form method="POST" action="{{ route('admin.data-field-names.update', $row) }}">
                        @csrf
                        @method('PUT')
                        <td><input type="text" name="name" class="form-control form-control-sm" value="{{ $row->name }}" required></td>
                        <td><input type="text" name="replace_code" class="form-control form-control-sm" value="{{ $row->replace_code }}" required pattern="[a-z][a-z0-9_]*"></td>
                        <td>
                            <input type="checkbox" name="is_active" value="1" @checked($row->is_active)>
                        </td>
                        <td><button class="btn btn-outline-primary btn-sm" type="submit">更新</button></td>
                    </form>
                </tr>
            @endforeach
        </tbody>
    </table>
    {{ $names->links() }}

    <details class="mt-3">
        <summary class="small text-muted">予約語一覧</summary>
        <p class="small mb-0"><code>{{ implode(', ', $reservedCodes) }}</code></p>
    </details>
@endsection
