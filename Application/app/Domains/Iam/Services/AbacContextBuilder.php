<?php

namespace App\Domains\Iam\Services;

use App\Models\User;
use Illuminate\Support\Arr;

class AbacContextBuilder
{
    public function __construct(
        private readonly RbacService $rbac,
        private readonly BpRelationResolver $relationResolver,
    ) {}

    /**
     * @param  array<string, mixed>|null  $resource
     * @param  array<string, mixed>  $context
     * @return array{subject: array<string, mixed>, resource: array<string, mixed>, action: string, environment: array<string, mixed>}
     */
    public function build(User $user, string $action, ?array $resource = null, array $context = []): array
    {
        $user->loadMissing(['businessPartner', 'customer.managingBp', 'roles']);

        $actorBpId = $user->bp_id
            ?? $user->customer?->managing_bp_id;

        $resource = $resource ?? [];
        $targetBpId = $resource['owner_bp_id'] ?? $resource['bp_id'] ?? null;

        $relation = $this->relationResolver->resolve(
            $actorBpId ? (int) $actorBpId : null,
            $targetBpId !== null ? (int) $targetBpId : null
        );

        $resourceAttributes = array_merge($resource, $relation, [
            'resource_type' => $resource['resource_type'] ?? $resource['type'] ?? '*',
        ]);

        return [
            'subject' => [
                'user_id' => $user->id,
                'user_type' => $user->user_type->value,
                'bp_id' => $user->bp_id,
                'bp_depth' => $user->businessPartner?->depth,
                'customer_id' => $user->customer_id,
                'role_codes' => $user->roles->pluck('code')->values()->all(),
                'permission_codes' => $this->rbac->permissionCodes($user)->values()->all(),
                'is_2fa_ok' => $user->two_factor_confirmed_at !== null || $user->two_factor_forced_disabled,
                'depth_diff_to_applicant' => $relation['depth_diff_to_applicant'],
            ],
            'resource' => $resourceAttributes,
            'action' => $action,
            'environment' => array_merge([
                'now' => now()->toIso8601String(),
                'channel' => 'web',
            ], Arr::get($context, 'environment', [])),
        ];
    }
}
