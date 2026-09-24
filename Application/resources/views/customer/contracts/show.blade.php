@extends('layouts.app')

@section('title', '契約詳細')
@section('area', 'カスタマー')
@section('logout_action', route('customer.logout'))

@section('content')
    @include('partials.breadcrumb', [
        'crumbs' => [['label' => '契約一覧', 'url' => route('customer.contracts.index')]],
        'current' => '契約詳細',
    ])
    <h1 class="h3 mb-3">契約詳細</h1>

    <dl class="row">
        <dt class="col-sm-3">契約番号</dt><dd class="col-sm-9"><code>{{ $contract->code }}</code></dd>
        <dt class="col-sm-3">状態</dt><dd class="col-sm-9">{{ $contract->status->label() }}</dd>
        <dt class="col-sm-3">拠点</dt><dd class="col-sm-9">{{ $contract->site?->name }}</dd>
        <dt class="col-sm-3">管理BP</dt><dd class="col-sm-9">{{ $contract->owningBp?->code }}</dd>
    </dl>

    <h2 class="h5">明細・データ・ドキュメント</h2>
    @if (($contract->dataRows ?? collect())->isNotEmpty())
        <div class="card mb-3">
            <div class="card-body">
                <h3 class="h6">契約共通データ</h3>
                <ul class="mb-0">
                    @foreach ($contract->dataRows as $row)
                        <li><strong>{{ $row->name }}</strong>: {{ $row->value }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif
    @foreach ($contract->items as $line)
        <div class="card mb-3">
            <div class="card-body">
                <h3 class="h6">{{ $line->item?->code }} / {{ $line->item?->name }}</h3>
                <p class="small mb-2">
                    請求額:
                    @include('partials.price-display', ['amount' => $line->unit_price, 'taxRate' => $line->tax_rate])
                </p>
                <h4 class="h6">データ</h4>
                <ul>
                    @forelse ($line->dataRows as $row)
                        <li><strong>{{ $row->name }}</strong>: {{ $row->value }}</li>
                    @empty
                        <li class="text-muted">未登録</li>
                    @endforelse
                </ul>
                <h4 class="h6">ドキュメント</h4>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>タイトル</th>
                                <th>ファイル</th>
                                <th>区分</th>
                                <th class="text-end">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($line->documents as $doc)
                                <tr>
                                    <td>{{ $doc->title }}</td>
                                    <td><code class="small">{{ $doc->original_name ?: '—' }}</code></td>
                                    <td>
                                        @if ($contract->status->value === 'activated')
                                            <span class="badge text-bg-success">ダウンロード可</span>
                                        @else
                                            <span class="badge text-bg-secondary">準備中</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        <a href="{{ route('customer.contracts.items.documents.download', [$line, $doc->id]) }}" class="btn btn-outline-secondary btn-sm">DL</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-muted">なし</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endforeach
@endsection
