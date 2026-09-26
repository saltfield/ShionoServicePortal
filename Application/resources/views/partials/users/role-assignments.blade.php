{{--
  @var \App\Models\User $managedUser
  @var \Illuminate\Support\Collection<int, \App\Models\Role> $assignableRoles
  @var string $scopeLabel
  @var string $prefix
--}}
@php
    $assignableRoles = $assignableRoles ?? collect();
    $scopeLabel = $scopeLabel ?? '—';
    $prefix = $prefix ?? ($routePrefix ?? 'admin');
@endphp

<div class="card card-body mb-4" style="max-width:40rem">
    <h2 class="h5 mb-3">ロールの割り当て</h2>
    @if (session('status') && (str_contains(session('status'), 'ロール')))
        <div class="alert alert-success py-2">{{ session('status') }}</div>
    @endif
    @if ($errors->has('role_code'))
        <div class="alert alert-danger py-2">{{ $errors->first('role_code') }}</div>
    @endif

    <div class="table-responsive mb-3">
        <table class="table table-sm align-middle mb-0">
            <thead>
            <tr>
                <th>ロール</th>
                <th>スコープ</th>
                <th class="text-end">操作</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($managedUser->roles as $role)
                <tr>
                    <td>
                        <div class="fw-semibold">{{ $role->name }}</div>
                        <code class="small">{{ $role->code }}</code>
                    </td>
                    <td class="small text-muted">{{ $scopeLabel }}</td>
                    <td class="text-end">
                        @if ($managedUser->roles->count() > 1)
                            <form method="POST" action="{{ route($prefix.'.users.roles.revoke', $managedUser) }}" class="d-inline"
                                  onsubmit="return confirm('このロールの割り当てを解除しますか？');">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="role_code" value="{{ $role->code }}">
                                @if ($returnCustomerId ?? null)
                                    <input type="hidden" name="return_customer_id" value="{{ $returnCustomerId }}">
                                @endif
                                @if ($returnBpId ?? null)
                                    <input type="hidden" name="return_bp_id" value="{{ $returnBpId }}">
                                @endif
                                <button type="submit" class="btn btn-outline-danger btn-sm">解除</button>
                            </form>
                        @else
                            <span class="small text-muted">必須</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="3" class="text-muted">割り当てなし</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($assignableRoles->isNotEmpty())
        <form method="POST" action="{{ route($prefix.'.users.roles.assign', $managedUser) }}" class="border-top pt-3">
            @csrf
            @if ($returnCustomerId ?? null)
                <input type="hidden" name="return_customer_id" value="{{ $returnCustomerId }}">
            @endif
            @if ($returnBpId ?? null)
                <input type="hidden" name="return_bp_id" value="{{ $returnBpId }}">
            @endif
            <label class="form-label" for="assign_role_code">割り当てを追加</label>
            <select name="role_code" id="assign_role_code" class="form-select mb-2" required>
                <option value="">選択してください</option>
                @foreach ($assignableRoles as $role)
                    <option
                        value="{{ $role->code }}"
                        data-permissions="{{ $role->permissions->pluck('code')->implode(', ') }}"
                    >{{ $role->name }}（{{ $role->code }}）</option>
                @endforeach
            </select>
            <p class="small text-muted mb-2" id="assign_role_permissions">含まれる権限: —</p>
            <button type="submit" class="btn btn-outline-primary btn-sm">追加</button>
        </form>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const select = document.getElementById('assign_role_code');
                const preview = document.getElementById('assign_role_permissions');
                if (!select || !preview) return;
                const sync = () => {
                    const option = select.selectedOptions[0];
                    const perms = option?.dataset?.permissions || '';
                    preview.textContent = perms
                        ? '含まれる権限: ' + perms
                        : '含まれる権限: —';
                };
                select.addEventListener('change', sync);
                sync();
            });
        </script>
    @else
        <p class="small text-muted mb-0">追加できるロールはありません。</p>
    @endif
</div>
