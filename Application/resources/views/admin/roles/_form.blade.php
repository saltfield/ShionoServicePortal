{{--
  @var \App\Models\Role|null $managedRole
  @var \Illuminate\Support\Collection $permissionGroups
  @var list<\App\Domains\Iam\Enums\RoleScope> $scopes
  @var list<string> $selectedPermissionCodes
  @var bool $creating
--}}
@php
    $isSystemAdmin = $managedRole?->code === 'system_admin';
    $isBuiltin = $managedRole?->isBuiltin() ?? false;
    $selected = collect($selectedPermissionCodes ?? []);
@endphp

<div class="mb-3">
    <label class="form-label" for="code">コード</label>
    @if ($creating)
        <input type="text" name="code" id="code" class="form-control" value="{{ old('code') }}" required
               pattern="[a-z][a-z0-9_]{1,63}" placeholder="例: bp_custom_ops">
        <div class="form-text">英小文字・数字・アンダースコア（先頭は英字）</div>
    @else
        <input type="text" id="code" class="form-control" value="{{ $managedRole->code }}" disabled>
    @endif
</div>

<div class="mb-3">
    <label class="form-label" for="name">表示名</label>
    <input type="text" name="name" id="name" class="form-control" value="{{ old('name', $managedRole?->name) }}" required>
</div>

<div class="mb-3">
    <label class="form-label" for="scope">スコープ</label>
    @if ($creating || ! $isBuiltin)
        <select name="scope" id="scope" class="form-select" required>
            <option value="" @selected(old('scope', $managedRole?->scope) === null || old('scope', $managedRole?->scope) === '')>選択してください</option>
            <option value="system" @selected(old('scope', $managedRole?->scope) === 'system')>system（管理者ユーザー向け）</option>
            <option value="bp" @selected(old('scope', $managedRole?->scope) === 'bp')>bp（BPユーザー向け）</option>
            <option value="customer" @selected(old('scope', $managedRole?->scope) === 'customer')>customer（カスタマーユーザー向け）</option>
        </select>
        <div class="form-text">
            BP画面のユーザー管理に出すには <strong>bp</strong> を選んでください。
            カスタマー画面向けは <strong>customer</strong> です。
        </div>
    @else
        <input type="text" class="form-control" value="{{ $managedRole->scope }}" disabled>
        <div class="form-text">組み込みロールのスコープは変更できません。</div>
    @endif
</div>

<div class="mb-3">
    <label class="form-label" for="description">説明</label>
    <textarea name="description" id="description" class="form-control" rows="2">{{ old('description', $managedRole?->description) }}</textarea>
</div>

<div class="mb-2">
    <div class="form-label">権限</div>
    @if ($isSystemAdmin)
        <p class="small text-muted mb-2">システム管理者は全権限を常に保持します（変更不可）。</p>
    @endif
</div>

@foreach ($permissionGroups as $resource => $permissions)
    <div class="mb-3 border rounded p-3">
        <div class="fw-semibold small text-uppercase text-muted mb-2">{{ $resource }}</div>
        <div class="row g-2">
            @foreach ($permissions as $permission)
                <div class="col-md-6">
                    <div class="form-check">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="permission_codes[]"
                            id="perm_{{ $permission->id }}"
                            value="{{ $permission->code }}"
                            @checked($isSystemAdmin || $selected->contains($permission->code))
                            @disabled($isSystemAdmin)
                        >
                        <label class="form-check-label" for="perm_{{ $permission->id }}">
                            {{ $permission->name }}
                            <code class="small text-muted">{{ $permission->code }}</code>
                        </label>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endforeach
