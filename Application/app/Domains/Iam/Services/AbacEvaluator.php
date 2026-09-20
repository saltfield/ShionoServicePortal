<?php

namespace App\Domains\Iam\Services;

use App\Models\Policy;
use Illuminate\Support\Collection;

class AbacEvaluator
{
    /**
     * @param  array{subject: array, resource: array, action: string, environment: array}  $context
     */
    public function allows(array $context): bool
    {
        $policies = $this->applicablePolicies(
            $context['action'],
            (string) ($context['resource']['resource_type'] ?? '*')
        );

        if ($policies->isEmpty()) {
            return true;
        }

        $matched = $policies->filter(fn (Policy $policy) => $this->matches($policy, $context));

        if ($matched->contains(fn (Policy $policy) => $policy->effect === 'deny')) {
            return false;
        }

        $allowPolicies = $policies->where('effect', 'allow');

        if ($allowPolicies->isEmpty()) {
            // Only deny policies exist for this action; none matched → allow
            return true;
        }

        return $matched->contains(fn (Policy $policy) => $policy->effect === 'allow');
    }

    /**
     * @return Collection<int, Policy>
     */
    private function applicablePolicies(string $action, string $resourceType): Collection
    {
        return Policy::query()
            ->with('conditions')
            ->where('is_active', true)
            ->where(function ($query) use ($action) {
                $query->where('action', '*')->orWhere('action', $action);
            })
            ->where(function ($query) use ($resourceType) {
                $query->where('resource', '*')->orWhere('resource', $resourceType);
            })
            ->orderByDesc('priority')
            ->orderByRaw("CASE effect WHEN 'deny' THEN 0 ELSE 1 END")
            ->get();
    }

    private function matches(Policy $policy, array $context): bool
    {
        $conditions = $policy->conditions;

        if ($conditions->isEmpty()) {
            return true;
        }

        $groups = $conditions->groupBy('group_no');

        foreach ($groups as $groupConditions) {
            $groupPass = false;

            foreach ($groupConditions as $condition) {
                if ($this->evaluateCondition($condition->attribute, $condition->operator, $condition->value_json, $context)) {
                    $groupPass = true;
                    break;
                }
            }

            if (! $groupPass) {
                return false;
            }
        }

        return true;
    }

    private function evaluateCondition(string $attribute, string $operator, mixed $expected, array $context): bool
    {
        $actual = data_get($context, $attribute);

        return match ($operator) {
            'eq' => $actual == ($expected['value'] ?? $expected),
            'neq' => $actual != ($expected['value'] ?? $expected),
            'in' => in_array($actual, $expected['value'] ?? (array) $expected, true),
            'gte' => is_numeric($actual) && $actual >= ($expected['value'] ?? $expected),
            'lte' => is_numeric($actual) && $actual <= ($expected['value'] ?? $expected),
            'exists' => ($expected['value'] ?? $expected)
                ? ($actual !== null && $actual !== '' && $actual !== [])
                : ($actual === null || $actual === '' || $actual === []),
            'contains' => is_array($actual) && in_array($expected['value'] ?? $expected, $actual, true),
            'not_contains' => is_array($actual) && ! in_array($expected['value'] ?? $expected, $actual, true),
            default => false,
        };
    }
}
