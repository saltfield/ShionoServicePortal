<?php

namespace App\Domains\Notification\Services;

use App\Domains\Auth\Enums\UserType;
use App\Domains\Iam\Services\RbacService;
use App\Domains\Notification\Enums\NotificationType;
use App\Models\User;
use App\Models\UserNotificationPreference;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class NotificationDispatcher
{
    public function __construct(
        private readonly RbacService $rbac,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, bool>
     */
    public function enabledMapFromRequest(array $raw): array
    {
        $enabled = [];
        foreach (NotificationType::userConfigurable() as $type) {
            $enabled[$type->value] = filter_var($raw[$type->value] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        return $enabled;
    }

    /**
     * @param  iterable<User|null>  $recipients
     */
    public function send(NotificationType $type, iterable $recipients, object $notification, ?User $except = null): void
    {
        $users = collect($recipients)
            ->filter(fn ($user) => $user instanceof User)
            ->unique('id')
            ->values();

        if ($except !== null) {
            $users = $users->reject(fn (User $user) => (int) $user->id === (int) $except->id)->values();
        }

        $deliverable = $users->filter(function (User $user) use ($type) {
            if (! $user->is_active) {
                return false;
            }
            if (! filled($user->email)) {
                Log::info('notification.skipped_no_email', [
                    'type' => $type->value,
                    'user_id' => $user->id,
                    'login_id' => $user->login_id,
                ]);

                return false;
            }
            if (! $this->isEnabled($user, $type)) {
                Log::info('notification.skipped_opt_out', [
                    'type' => $type->value,
                    'user_id' => $user->id,
                ]);

                return false;
            }

            return true;
        })->values();

        if ($deliverable->isEmpty()) {
            Log::info('notification.no_recipients', [
                'type' => $type->value,
                'candidate_count' => $users->count(),
                'candidate_ids' => $users->pluck('id')->all(),
            ]);

            return;
        }

        Log::info('notification.queued', [
            'type' => $type->value,
            'recipient_ids' => $deliverable->pluck('id')->all(),
            'recipient_emails' => $deliverable->pluck('email')->all(),
        ]);

        Notification::send($deliverable, $notification);
    }

    public function isEnabled(User $user, NotificationType $type): bool
    {
        if (! $type->allowsOptOut()) {
            return true;
        }

        $pref = UserNotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('notification_type', $type->value)
            ->first();

        return $pref === null ? true : (bool) $pref->enabled;
    }

    /**
     * @param  array<string, bool>  $enabledByType
     */
    public function syncPreferences(User $user, array $enabledByType): void
    {
        foreach (NotificationType::userConfigurable() as $type) {
            $enabled = (bool) ($enabledByType[$type->value] ?? true);
            UserNotificationPreference::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'notification_type' => $type->value,
                ],
                ['enabled' => $enabled],
            );
        }
    }

    /**
     * @return array<string, bool>
     */
    public function preferencesFor(User $user): array
    {
        $stored = UserNotificationPreference::query()
            ->where('user_id', $user->id)
            ->pluck('enabled', 'notification_type');

        $result = [];
        foreach (NotificationType::cases() as $type) {
            if (! $type->allowsOptOut()) {
                $result[$type->value] = true;
                continue;
            }
            $result[$type->value] = array_key_exists($type->value, $stored->all())
                ? (bool) $stored[$type->value]
                : true;
        }

        return $result;
    }

    /**
     * @return Collection<int, User>
     */
    public function usersWithPermission(?int $bpId, string $permission, bool $includeAdmins = false): Collection
    {
        $users = collect();

        if ($bpId !== null) {
            $bpUsers = User::query()
                ->where('user_type', UserType::Bp->value)
                ->where('bp_id', $bpId)
                ->where('is_active', true)
                ->get();
            $users = $users->merge(
                $bpUsers->filter(fn (User $user) => $this->rbac->hasPermission($user, $permission))
            );
        }

        if ($includeAdmins) {
            $admins = User::query()
                ->where('user_type', UserType::Admin->value)
                ->where('is_active', true)
                ->get();
            $users = $users->merge(
                $admins->filter(fn (User $user) => $this->rbac->hasPermission($user, $permission))
            );
        }

        return $users->unique('id')->values();
    }
}
