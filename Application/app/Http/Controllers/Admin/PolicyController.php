<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Iam\Services\AuthorizationService;
use App\Domains\Iam\Services\PolicyManagementService;
use App\Http\Controllers\Controller;
use App\Models\Policy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class PolicyController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization, PolicyManagementService $policies): View
    {
        $authorization->authorize($request->user('admin'), 'iam.policy.manage');

        return view('admin.policies.index', [
            'policies' => $policies->listPolicies(),
        ]);
    }

    public function create(Request $request, AuthorizationService $authorization, PolicyManagementService $policies): View
    {
        $authorization->authorize($request->user('admin'), 'iam.policy.manage');

        return view('admin.policies.create', [
            'actionOptions' => $policies->actionOptions(),
            'operators' => PolicyManagementService::OPERATORS,
            'commonAttributes' => PolicyManagementService::COMMON_ATTRIBUTES,
            'conditionRows' => old('conditions', [[
                'group_no' => 1,
                'attribute' => '',
                'operator' => 'eq',
                'value' => '',
            ]]),
        ]);
    }

    public function store(Request $request, PolicyManagementService $policies): RedirectResponse
    {
        $validated = $this->validatedPolicy($request);

        try {
            $policy = $policies->create(
                $request->user('admin'),
                $validated,
                $validated['conditions'] ?? [],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['code' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.policies.edit', $policy)
            ->with('status', "ポリシー {$policy->code} を作成しました。");
    }

    public function edit(Request $request, Policy $policy, AuthorizationService $authorization, PolicyManagementService $policies): View
    {
        $authorization->authorize($request->user('admin'), 'iam.policy.manage');
        $policy->load('conditions');

        $conditionRows = old('conditions');
        if (! is_array($conditionRows)) {
            $conditionRows = $policy->conditions->map(fn ($c) => [
                'group_no' => $c->group_no,
                'attribute' => $c->attribute,
                'operator' => $c->operator,
                'value' => $this->formatConditionValue($c->value_json),
            ])->all();
            if ($conditionRows === []) {
                $conditionRows = [['group_no' => 1, 'attribute' => '', 'operator' => 'eq', 'value' => '']];
            }
        }

        return view('admin.policies.edit', [
            'managedPolicy' => $policy,
            'actionOptions' => $policies->actionOptions(),
            'operators' => PolicyManagementService::OPERATORS,
            'commonAttributes' => PolicyManagementService::COMMON_ATTRIBUTES,
            'conditionRows' => $conditionRows,
        ]);
    }

    public function update(Request $request, Policy $policy, PolicyManagementService $policies): RedirectResponse
    {
        $validated = $this->validatedPolicy($request, creating: false);

        try {
            $policies->update(
                $request->user('admin'),
                $policy,
                $validated,
                $validated['conditions'] ?? [],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.policies.edit', $policy)
            ->with('status', 'ポリシーを更新しました。');
    }

    public function destroy(Request $request, Policy $policy, PolicyManagementService $policies): RedirectResponse
    {
        try {
            $policies->delete($request->user('admin'), $policy);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['policy' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.policies.index')
            ->with('status', 'ポリシーを削除しました。');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPolicy(Request $request, bool $creating = true): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'effect' => ['required', Rule::in(['allow', 'deny'])],
            'resource' => ['required', 'string', 'max:100'],
            'action' => ['required', 'string', 'max:100'],
            'priority' => ['required', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
            'conditions' => ['nullable', 'array'],
            'conditions.*.group_no' => ['nullable', 'integer', 'min:1'],
            'conditions.*.attribute' => ['nullable', 'string', 'max:100'],
            'conditions.*.operator' => ['nullable', 'string', 'max:20'],
            'conditions.*.value' => ['nullable'],
        ];

        if ($creating) {
            $rules['code'] = ['required', 'string', 'max:100'];
        }

        $validated = $request->validate($rules);
        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['conditions'] = array_values($request->input('conditions', []));

        return $validated;
    }

    private function formatConditionValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_array($value)) {
            return implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $value));
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
