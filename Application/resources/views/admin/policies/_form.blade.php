{{--
  @var \App\Models\Policy|null $managedPolicy
  @var list<string> $actionOptions
  @var list<string> $operators
  @var list<string> $commonAttributes
  @var list<array{group_no:mixed, attribute:string, operator:string, value:mixed}> $conditionRows
  @var bool $creating
--}}
@php
    $conditionRows = $conditionRows ?? [['group_no' => 1, 'attribute' => '', 'operator' => 'eq', 'value' => '']];
@endphp

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <label class="form-label" for="code">コード</label>
        @if ($creating)
            <input type="text" name="code" id="code" class="form-control" value="{{ old('code') }}" required
                   placeholder="例: P10_custom_deny">
        @else
            <input type="text" id="code" class="form-control" value="{{ $managedPolicy->code }}" disabled>
        @endif
    </div>
    <div class="col-md-6">
        <label class="form-label" for="name">表示名</label>
        <input type="text" name="name" id="name" class="form-control" value="{{ old('name', $managedPolicy?->name) }}" required>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <label class="form-label" for="effect">効果</label>
        <select name="effect" id="effect" class="form-select" required>
            <option value="allow" @selected(old('effect', $managedPolicy?->effect) === 'allow')>allow（許可）</option>
            <option value="deny" @selected(old('effect', $managedPolicy?->effect) === 'deny')>deny（拒否・優先）</option>
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label" for="priority">優先度</label>
        <input type="number" name="priority" id="priority" class="form-control"
               value="{{ old('priority', $managedPolicy?->priority ?? 100) }}" min="0" max="10000" required>
        <div class="form-text">大きいほど先に評価。deny は同優先でも優遇。</div>
    </div>
    <div class="col-md-3">
        <label class="form-label" for="resource">resource</label>
        <input type="text" name="resource" id="resource" class="form-control" list="resource_suggestions"
               value="{{ old('resource', $managedPolicy?->resource ?? '*') }}" required>
        <datalist id="resource_suggestions">
            <option value="*"></option>
            <option value="contract"></option>
            <option value="inquiry"></option>
            <option value="price"></option>
        </datalist>
    </div>
    <div class="col-md-3">
        <label class="form-label" for="action">action</label>
        <input type="text" name="action" id="action" class="form-control" list="action_suggestions"
               value="{{ old('action', $managedPolicy?->action ?? '*') }}" required>
        <datalist id="action_suggestions">
            @foreach ($actionOptions as $action)
                <option value="{{ $action }}"></option>
            @endforeach
        </datalist>
    </div>
</div>

<div class="mb-3">
    <label class="form-label" for="description">説明</label>
    <textarea name="description" id="description" class="form-control" rows="2">{{ old('description', $managedPolicy?->description) }}</textarea>
</div>

<div class="form-check mb-4">
    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active"
           @checked(old('is_active', $managedPolicy?->is_active ?? true))>
    <label class="form-check-label" for="is_active">有効</label>
</div>

<div class="d-flex justify-content-between align-items-center mb-2">
    <div>
        <div class="form-label mb-0">条件</div>
        <div class="form-text">同一 group_no は OR、異なる group_no は AND。空行は無視されます。</div>
    </div>
    <button type="button" class="btn btn-outline-secondary btn-sm" id="addConditionRow">行を追加</button>
</div>

<div class="table-responsive mb-2">
    <table class="table table-sm align-middle" id="conditionTable">
        <thead>
        <tr>
            <th style="width:5rem">group</th>
            <th>attribute</th>
            <th style="width:9rem">operator</th>
            <th>value</th>
            <th style="width:4rem"></th>
        </tr>
        </thead>
        <tbody>
        @foreach ($conditionRows as $index => $row)
            <tr class="condition-row">
                <td>
                    <input type="number" name="conditions[{{ $index }}][group_no]" class="form-control form-control-sm"
                           value="{{ $row['group_no'] ?? 1 }}" min="1">
                </td>
                <td>
                    <input type="text" name="conditions[{{ $index }}][attribute]" class="form-control form-control-sm"
                           list="attribute_suggestions" value="{{ $row['attribute'] ?? '' }}" placeholder="resource.relation">
                </td>
                <td>
                    <select name="conditions[{{ $index }}][operator]" class="form-select form-select-sm">
                        @foreach ($operators as $operator)
                            <option value="{{ $operator }}" @selected(($row['operator'] ?? 'eq') === $operator)>{{ $operator }}</option>
                        @endforeach
                    </select>
                </td>
                <td>
                    <input type="text" name="conditions[{{ $index }}][value]" class="form-control form-control-sm"
                           value="{{ $row['value'] ?? '' }}" placeholder="same_bp, descendant / 1000000">
                </td>
                <td class="text-end">
                    <button type="button" class="btn btn-outline-danger btn-sm remove-condition-row">×</button>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

<datalist id="attribute_suggestions">
    @foreach ($commonAttributes as $attribute)
        <option value="{{ $attribute }}"></option>
    @endforeach
</datalist>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const table = document.getElementById('conditionTable')?.querySelector('tbody');
        const addButton = document.getElementById('addConditionRow');
        if (!table || !addButton) return;

        const reindex = () => {
            [...table.querySelectorAll('tr.condition-row')].forEach((row, index) => {
                row.querySelectorAll('input, select').forEach((el) => {
                    if (!el.name) return;
                    el.name = el.name.replace(/conditions\[\d+]/, `conditions[${index}]`);
                });
            });
        };

        addButton.addEventListener('click', () => {
            const first = table.querySelector('tr.condition-row');
            if (!first) return;
            const clone = first.cloneNode(true);
            clone.querySelectorAll('input').forEach((el) => {
                if (el.type === 'number') el.value = '1';
                else el.value = '';
            });
            const operator = clone.querySelector('select');
            if (operator) operator.value = 'eq';
            table.appendChild(clone);
            reindex();
        });

        table.addEventListener('click', (event) => {
            const button = event.target.closest('.remove-condition-row');
            if (!button) return;
            const rows = table.querySelectorAll('tr.condition-row');
            if (rows.length <= 1) {
                rows[0].querySelectorAll('input').forEach((el) => {
                    if (el.type === 'number') el.value = '1';
                    else el.value = '';
                });
                return;
            }
            button.closest('tr')?.remove();
            reindex();
        });
    });
</script>
@endpush
