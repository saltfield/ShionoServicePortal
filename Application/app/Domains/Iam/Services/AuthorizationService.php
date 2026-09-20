<?php

namespace App\Domains\Iam\Services;

use App\Models\User;

class AuthorizationService
{
    public function __construct(
        private readonly RbacService $rbac,
        private readonly AbacContextBuilder $contextBuilder,
        private readonly AbacEvaluator $abacEvaluator,
    ) {}

    public function can(User $user, string $permission, mixed $resource = null, array $context = []): bool
    {
        if (! $this->rbac->hasPermission($user, $permission)) {
            return false;
        }

        $resourceAttributes = $this->normalizeResource($resource);

        $abacContext = $this->contextBuilder->build(
            $user,
            $permission,
            $resourceAttributes,
            $context
        );

        return $this->abacEvaluator->allows($abacContext);
    }

    public function authorize(User $user, string $permission, mixed $resource = null, array $context = []): void
    {
        if (! $this->can($user, $permission, $resource, $context)) {
            abort(403, 'この操作を行う権限がありません。');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeResource(mixed $resource): ?array
    {
        if ($resource === null) {
            return null;
        }

        if (is_array($resource)) {
            return $resource;
        }

        if (is_object($resource) && method_exists($resource, 'toAbacAttributes')) {
            return $resource->toAbacAttributes();
        }

        return null;
    }
}
