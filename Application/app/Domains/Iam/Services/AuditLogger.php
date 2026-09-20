<?php

namespace App\Domains\Iam\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogger
{
    public function log(
        string $category,
        string $action,
        string $result,
        ?User $actor = null,
        ?User $targetUser = null,
        ?string $targetType = null,
        ?int $targetId = null,
        array $meta = [],
        ?Request $request = null,
    ): AuditLog {
        $request ??= request();

        return AuditLog::query()->create([
            'category' => $category,
            'action' => $action,
            'result' => $result,
            'actor_user_id' => $actor?->id,
            'target_user_id' => $targetUser?->id,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'meta' => $meta ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }
}
