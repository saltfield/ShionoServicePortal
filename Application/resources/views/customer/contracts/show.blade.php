@extends('layouts.app')

@section('title', '契約詳細')
@section('area', 'カスタマー')
@section('logout_action', route('customer.logout'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">契約詳細</h1>
        <a href="{{ route('customer.contracts.index') }}" class="btn btn-outline-secondary btn-sm">一覧へ</a>
    </div>

    <dl class="row">
        <dt class="col-sm-3">契約番号</dt><dd class="col-sm-9"><code>{{ $contract->code }}</code></dd>
        <dt class="col-sm-3">状態</dt><dd class="col-sm-9">{{ $contract->status->label() }}</dd>
        <dt class="col-sm-3">拠点</dt><dd class="col-sm-9">{{ $contract->site?->name }}</dd>
        <dt class="col-sm-3">管理BP</dt><dd class="col-sm-9">{{ $contract->owningBp?->code }}</dd>
    </dl>

    <h2 class="h5">明細・データ・ドキュメント</h2>
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
                <ul class="mb-0">
                    @forelse ($line->documents as $doc)
                        <li>
                            <a href="{{ route('customer.contracts.items.documents.download', [$line, $doc->id]) }}">{{ $doc->title }}</a>
                        </li>
                    @empty
                        <li class="text-muted">なし</li>
                    @endforelse
                </ul>
            </div>
        </div>
    @endforeach
@endsection
