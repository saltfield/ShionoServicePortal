<?php

namespace App\Domains\Iam\Services;

use App\Models\Permission;
use App\Models\Policy;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PolicyManagementService
{
    public const OPERATORS = [
        'eq',
        'neq',
        'in',
        'gte',
        'lte',
        'exists',
        'contains',
        'not_contains',
    ];

    public const COMMON_ATTRIBUTES = [
        'subject.user_type',
        'subject.permission_codes',
        'subject.depth_diff_to_applicant',
        'resource.relation',
        'resource.depth_diff',
        'resource.amount',
        'resource.status',
        'resource.resource_type',
    ];

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return Collection<int, Policy>
     */
    public function listPolicies(): Collection
    {
        return Policy::query()
            ->withCount('conditions')
            ->orderByDesc('priority')
            ->orderBy('code')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{group_no:int|string, attribute:string, operator:string, value:mixed}>  $conditions
     */
    public function create(User $actor, array $data, array $conditions): Policy
    {
        $this->authorization->authorize($actor, 'iam.policy.manage');

        $code = $this->normalizeCode((string) $data['code']);
        if (Policy::query()->where('code', $code)->exists()) {
            throw new InvalidArgumentException('このポリシーコードは既に使用されています。');
        }

        $normalizedConditions = $this->normalizeConditions($conditions);

        return DB::transaction(function () use ($actor, $data, $code, $normalizedConditions) {
            $policy = Policy::query()->create([
                'code' => $code,
                'name' => (string) $data['name'],
                'effect' => (string) $data['effect'],
                'resource' => (string) ($data['resource'] ?: '*'),
                'action' => (string) ($data['action'] ?: '*'),
                'priority' => (int) ($data['priority'] ?? 100),
                'is_active' => (bool) ($data['is_active'] ?? true),
                'description' => $data['description'] ?? null,
            ]);

            $this->syncConditions($policy, $normalizedConditions);

            $this->auditLogger->log(
                'iam',
                'policy.create',
                'success',
                $actor,
                targetType: Policy::class,
                targetId: $policy->id,
                meta: ['code' => $policy->code, 'effect' => $policy->effect],
            );

            return $policy->load('conditions');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{group_no:int|string, attribute:string, operator:string, value:mixed}>  $conditions
     */
    public function update(User $actor, Policy $policy, array $data, array $conditions): Policy
    {
        $this->authorization->authorize($actor, 'iam.policy.manage');
        $normalizedConditions = $this->normalizeConditions($conditions);

        return DB::transaction(function () use ($actor, $policy, $data, $normalizedConditions) {
            $policy->name = (string) $data['name'];
            $policy->effect = (string) $data['effect'];
            $policy->resource = (string) ($data['resource'] ?: '*');
            $policy->action = (string) ($data['action'] ?: '*');
            $policy->priority = (int) ($data['priority'] ?? 100);
            $policy->is_active = (bool) ($data['is_active'] ?? false);
            $policy->description = $data['description'] ?? null;
            $policy->save();

            $this->syncConditions($policy, $normalizedConditions);

            $this->auditLogger->log(
                'iam',
                'policy.update',
                'success',
                $actor,
                targetType: Policy::class,
                targetId: $policy->id,
                meta: ['code' => $policy->code],
            );

            return $policy->fresh()->load('conditions');
        });
    }

    public function delete(User $actor, Policy $policy): void
    {
        $this->authorization->authorize($actor, 'iam.policy.manage');

        $code = $policy->code;
        $id = $policy->id;
        $policy->delete();

        $this->auditLogger->log(
            'iam',
            'policy.delete',
            'success',
            $actor,
            targetType: Policy::class,
            targetId: $id,
            meta: ['code' => $code],
        );
    }

    /**
     * @return list<string>
     */
    public function actionOptions(): array
    {
        return Permission::query()
            ->orderBy('code')
            ->pluck('code')
            ->prepend('*')
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeCode(string $code): string
    {
        $code = trim($code);
        if (! preg_match('/^[A-Za-z][A-Za-z0-9_.]{1,99}$/', $code)) {
            throw new InvalidArgumentException('ポリシーコードは英数字・アンダースコア・ドット（先頭は英字）で指定してください。');
        }

        return $code;
    }

    /**
     * @param  list<array{group_no:int|string, attribute:string, operator:string, value:mixed}>  $conditions
     * @return list<array{group_no:int, attribute:string, operator:string, value_json:mixed}>
     */
    private function normalizeConditions(array $conditions): array
    {
        $normalized = [];

        foreach ($conditions as $condition) {
            $attribute = trim((string) ($condition['attribute'] ?? ''));
            $operator = trim((string) ($condition['operator'] ?? ''));
            if ($attribute === '') {
                continue;
            }
            if ($operator === '') {
                throw new InvalidArgumentException('条件の演算子を指定してください。');
            }
            if (! in_array($operator, self::OPERATORS, true)) {
                throw new InvalidArgumentException("未対応の演算子です: {$operator}");
            }

            $normalized[] = [
                'group_no' => max(1, (int) ($condition['group_no'] ?? 1)),
                'attribute' => $attribute,
                'operator' => $operator,
                'value_json' => $this->parseConditionValue($condition['value'] ?? null, $operator),
            ];
        }

        return $normalized;
    }

    private function parseConditionValue(mixed $raw, string $operator): mixed
    {
        if (is_array($raw)) {
            return $raw;
        }

        $text = trim((string) $raw);
        if ($text === '') {
            return $operator === 'exists' ? true : null;
        }

        if ($operator === 'in') {
            if (str_starts_with($text, '[')) {
                $decoded = json_decode($text, true);
                if (! is_array($decoded)) {
                    throw new InvalidArgumentException('in 条件の値は JSON 配列またはカンマ区切りで指定してください。');
                }

                return $decoded;
            }

            return array_values(array_filter(array_map('trim', explode(',', $text)), fn ($v) => $v !== ''));
        }

        if (in_array($operator, ['gte', 'lte'], true) && is_numeric($text)) {
            return str_contains($text, '.') ? (float) $text : (int) $text;
        }

        if ($operator === 'exists') {
            return ! in_array(strtolower($text), ['0', 'false', 'no'], true);
        }

        if (str_starts_with($text, '[') || str_starts_with($text, '{')) {
            $decoded = json_decode($text, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $text;
    }

    /**
     * @param  list<array{group_no:int, attribute:string, operator:string, value_json:mixed}>  $conditions
     */
    private function syncConditions(Policy $policy, array $conditions): void
    {
        $policy->conditions()->delete();

        foreach ($conditions as $condition) {
            $policy->conditions()->create($condition);
        }
    }
}
