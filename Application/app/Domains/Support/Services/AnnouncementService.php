<?php

namespace App\Domains\Support\Services;

use App\Domains\Auth\Enums\UserType;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Iam\Services\AuditLogger;
use App\Domains\Iam\Services\AuthorizationService;
use App\Domains\Support\Enums\AnnouncementTargetType;
use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\AnnouncementTarget;
use App\Models\BusinessPartner;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AnnouncementService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditLogger $auditLogger,
        private readonly BpHierarchyService $hierarchy,
    ) {}

    /**
     * @param  list<array{type: string, id?: int|null}>  $targets
     * @param  array{
     *     published_at?: Carbon|string|null,
     *     expires_at?: Carbon|string|null,
     *     include_new_registrations?: bool
     * }  $options
     */
    public function publish(User $actor, string $title, string $body, array $targets, array $options = []): Announcement
    {
        $this->authorization->authorize($actor, 'announcement.manage');

        $title = trim($title);
        $body = trim($body);
        if ($title === '' || $body === '') {
            throw new InvalidArgumentException('タイトルと本文は必須です。');
        }
        if ($targets === []) {
            throw new InvalidArgumentException('配信先を1件以上指定してください。');
        }

        $includeNew = (bool) ($options['include_new_registrations'] ?? true);
        $publishedAt = $this->resolvePublishedAt($options['published_at'] ?? null);
        $expiresAt = $this->resolveExpiresAt($options['expires_at'] ?? null, $publishedAt);
        $normalized = $this->normalizeTargets($actor, $targets, $includeNew);

        return DB::transaction(function () use ($actor, $title, $body, $normalized, $publishedAt, $expiresAt, $includeNew) {
            $announcement = Announcement::query()->create([
                'title' => $title,
                'body' => $body,
                'published_at' => $publishedAt,
                'expires_at' => $expiresAt,
                'include_new_registrations' => $includeNew,
                'created_by_user_id' => $actor->id,
                'owning_bp_id' => $actor->user_type === UserType::Admin ? null : $actor->bp_id,
            ]);

            $this->replaceTargets($announcement, $normalized);

            $this->auditLogger->log(
                'announcement',
                'announcement.publish',
                'success',
                $actor,
                targetType: Announcement::class,
                targetId: $announcement->id,
                meta: ['title' => $title],
            );

            return $announcement->fresh(['targets']);
        });
    }

    /**
     * @param  list<array{type: string, id?: int|null}>  $targets
     * @param  array{
     *     published_at?: Carbon|string|null,
     *     expires_at?: Carbon|string|null,
     *     include_new_registrations?: bool
     * }  $options
     */
    public function update(User $actor, Announcement $announcement, string $title, string $body, array $targets, array $options = []): Announcement
    {
        $this->authorization->authorize($actor, 'announcement.manage');
        $this->assertManageable($actor, $announcement);

        $title = trim($title);
        $body = trim($body);
        if ($title === '' || $body === '') {
            throw new InvalidArgumentException('タイトルと本文は必須です。');
        }
        if ($targets === []) {
            throw new InvalidArgumentException('配信先を1件以上指定してください。');
        }

        $includeNew = (bool) ($options['include_new_registrations'] ?? $announcement->include_new_registrations);
        $publishedAt = array_key_exists('published_at', $options)
            ? $this->resolvePublishedAt($options['published_at'])
            : $announcement->published_at;
        $expiresAt = array_key_exists('expires_at', $options)
            ? $this->resolveExpiresAt($options['expires_at'], $publishedAt)
            : $announcement->expires_at;
        if ($expiresAt !== null && $expiresAt->lte($publishedAt)) {
            throw new InvalidArgumentException('公開終了は公開開始より後の日時を指定してください。');
        }
        $normalized = $this->normalizeTargets($actor, $targets, $includeNew);

        return DB::transaction(function () use ($actor, $announcement, $title, $body, $normalized, $publishedAt, $expiresAt, $includeNew) {
            $announcement->update([
                'title' => $title,
                'body' => $body,
                'published_at' => $publishedAt,
                'expires_at' => $expiresAt,
                'include_new_registrations' => $includeNew,
            ]);

            $this->replaceTargets($announcement, $normalized);

            $this->auditLogger->log(
                'announcement',
                'announcement.update',
                'success',
                $actor,
                targetType: Announcement::class,
                targetId: $announcement->id,
                meta: ['title' => $title],
            );

            return $announcement->fresh(['targets']);
        });
    }

    public function delete(User $actor, Announcement $announcement): void
    {
        $this->authorization->authorize($actor, 'announcement.manage');
        $this->assertManageable($actor, $announcement);

        $announcement->delete();

        $this->auditLogger->log(
            'announcement',
            'announcement.delete',
            'success',
            $actor,
            targetType: Announcement::class,
            targetId: $announcement->id,
        );
    }

    public function markRead(User $actor, Announcement $announcement): void
    {
        if (! $this->isVisibleTo($actor, $announcement)) {
            abort(403, 'このお知らせを参照する権限がありません。');
        }

        AnnouncementRead::query()->updateOrCreate(
            [
                'announcement_id' => $announcement->id,
                'user_id' => $actor->id,
            ],
            ['read_at' => now()],
        );
    }

    /**
     * @return Builder<Announcement>
     */
    public function visibleQuery(User $actor): Builder
    {
        $now = now();
        $query = Announcement::query()
            ->with(['targets', 'createdBy'])
            ->where('published_at', '<=', $now)
            ->where(function (Builder $builder) use ($now) {
                $builder->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $now);
            })
            ->latest('published_at');

        if ($actor->user_type === UserType::Admin) {
            return $query;
        }

        return $query->where(function (Builder $builder) use ($actor) {
            if ($actor->user_type === UserType::Bp && $actor->bp_id) {
                $bpIds = $this->hierarchy->descendantIdsIncludingSelf($actor->businessPartner);
                $ancestorIds = $this->hierarchy->ancestors($actor->businessPartner, includeSelf: true)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                $builder->where(function (Builder $bpVisible) use ($bpIds) {
                    $bpVisible->whereHas('targets', function (Builder $targetQuery) {
                        $targetQuery->where('target_type', AnnouncementTargetType::All->value);
                    })->orWhereHas('targets', function (Builder $targetQuery) {
                        $targetQuery->where('target_type', AnnouncementTargetType::AllBp->value);
                    })->orWhereHas('targets', function (Builder $targetQuery) use ($bpIds) {
                        $targetQuery->where('target_type', AnnouncementTargetType::Bp->value)
                            ->whereIn('target_id', $bpIds);
                    });
                });

                $builder->where(function (Builder $scope) use ($ancestorIds) {
                    $scope->whereNull('owning_bp_id')
                        ->orWhereIn('owning_bp_id', $ancestorIds);
                });
            }

            if ($actor->user_type === UserType::Customer && $actor->customer_id) {
                $actor->loadMissing('customer');
                $managingBpId = $actor->customer?->managing_bp_id ? (int) $actor->customer->managing_bp_id : null;

                $builder->where(function (Builder $customerVisible) use ($actor, $managingBpId) {
                    $customerVisible->whereHas('targets', function (Builder $targetQuery) {
                        $targetQuery->where('target_type', AnnouncementTargetType::All->value);
                    })->orWhereHas('targets', function (Builder $targetQuery) {
                        $targetQuery->where('target_type', AnnouncementTargetType::AllCustomer->value);
                    })->orWhereHas('targets', function (Builder $targetQuery) use ($actor) {
                        $targetQuery->where('target_type', AnnouncementTargetType::Customer->value)
                            ->where('target_id', $actor->customer_id);
                    });

                    // Specific BP targets: customers under that BP also see? Old code did this for BP targets.
                    // Keep: customer sees announcements targeted at their managing BP (specific bp target).
                    if ($managingBpId) {
                        $customerVisible->orWhereHas('targets', function (Builder $targetQuery) use ($managingBpId) {
                            $targetQuery->where('target_type', AnnouncementTargetType::Bp->value)
                                ->where('target_id', $managingBpId);
                        });
                    }
                });
            }
        });
    }

    public function unreadCount(User $actor): int
    {
        return $this->visibleQuery($actor)
            ->whereDoesntHave('reads', fn (Builder $q) => $q->where('user_id', $actor->id))
            ->count();
    }

    /**
     * @return Collection<int, Announcement>
     */
    public function manageableList(User $actor): Collection
    {
        $this->authorization->authorize($actor, 'announcement.manage');

        $query = Announcement::query()->with('targets')->latest('published_at');

        if ($actor->user_type === UserType::Bp) {
            $query->where('owning_bp_id', $actor->bp_id);
        }

        return $query->get();
    }

    public function isVisibleTo(User $actor, Announcement $announcement): bool
    {
        return $this->visibleQuery($actor)->whereKey($announcement->id)->exists();
    }

    private function assertManageable(User $actor, Announcement $announcement): void
    {
        if ($actor->user_type === UserType::Admin) {
            return;
        }

        if ($actor->user_type === UserType::Bp && $announcement->owning_bp_id === $actor->bp_id) {
            return;
        }

        abort(403, 'このお知らせを管理する権限がありません。');
    }

    private function resolvePublishedAt(Carbon|string|null $value): Carbon
    {
        if ($value === null || $value === '') {
            return now();
        }

        return $value instanceof Carbon ? $value->copy() : Carbon::parse($value, config('app.timezone'));
    }

    private function resolveExpiresAt(Carbon|string|null $value, Carbon $publishedAt): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        $expiresAt = $value instanceof Carbon ? $value->copy() : Carbon::parse($value, config('app.timezone'));
        if ($expiresAt->lte($publishedAt)) {
            throw new InvalidArgumentException('公開終了は公開開始より後の日時を指定してください。');
        }

        return $expiresAt;
    }

    /**
     * @param  list<array{type: AnnouncementTargetType, id: int|null}>  $normalized
     */
    private function replaceTargets(Announcement $announcement, array $normalized): void
    {
        $announcement->targets()->delete();

        foreach ($normalized as $target) {
            AnnouncementTarget::query()->create([
                'announcement_id' => $announcement->id,
                'target_type' => $target['type'],
                'target_id' => $target['id'],
            ]);
        }
    }

    /**
     * @param  list<array{type: string, id?: int|null}>  $targets
     * @return list<array{type: AnnouncementTargetType, id: int|null}>
     */
    private function normalizeTargets(User $actor, array $targets, bool $includeNewRegistrations): array
    {
        $broadcast = null;
        $specific = [];

        foreach ($targets as $target) {
            $type = AnnouncementTargetType::tryFrom((string) ($target['type'] ?? ''));
            if ($type === null) {
                throw new InvalidArgumentException('不正な配信先種別です。');
            }

            if ($type->isBroadcast()) {
                if ($broadcast !== null) {
                    throw new InvalidArgumentException('全体系の配信種別は1つのみ指定してください。');
                }
                if ($actor->user_type === UserType::Customer) {
                    throw new InvalidArgumentException('お知らせを配信する権限がありません。');
                }
                $broadcast = $type;
                continue;
            }

            $id = isset($target['id']) ? (int) $target['id'] : null;
            if ($id === null || $id < 1) {
                throw new InvalidArgumentException('配信先IDが必要です。');
            }

            if ($type === AnnouncementTargetType::Bp) {
                $bp = BusinessPartner::query()->findOrFail($id);
                if ($actor->user_type === UserType::Bp) {
                    $scope = $this->hierarchy->descendantIdsIncludingSelf($actor->businessPartner);
                    if (! in_array($bp->id, $scope, true)) {
                        throw new InvalidArgumentException('配下外のBPには配信できません。');
                    }
                }
                $specific[] = ['type' => $type, 'id' => $bp->id];
                continue;
            }

            $customer = Customer::query()->findOrFail($id);
            if ($actor->user_type === UserType::Bp) {
                $scope = $this->hierarchy->descendantIdsIncludingSelf($actor->businessPartner);
                if (! in_array($customer->managing_bp_id, $scope, true)) {
                    throw new InvalidArgumentException('配下外のカスタマーには配信できません。');
                }
            }
            $specific[] = ['type' => AnnouncementTargetType::Customer, 'id' => $customer->id];
        }

        if ($broadcast !== null && $specific !== []) {
            throw new InvalidArgumentException('全体系と個別配信先は同時に指定できません。');
        }

        if ($broadcast !== null) {
            if ($includeNewRegistrations) {
                return [['type' => $broadcast, 'id' => null]];
            }

            return $this->snapshotBroadcastTargets($actor, $broadcast);
        }

        if ($specific === []) {
            throw new InvalidArgumentException('配信先を1件以上指定してください。');
        }

        // Deduplicate
        $unique = [];
        $seen = [];
        foreach ($specific as $row) {
            $key = $row['type']->value.':'.$row['id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $row;
        }

        return $unique;
    }

    /**
     * @return list<array{type: AnnouncementTargetType, id: int|null}>
     */
    private function snapshotBroadcastTargets(User $actor, AnnouncementTargetType $broadcast): array
    {
        $bpQuery = BusinessPartner::query()->orderBy('id');
        $customerQuery = Customer::query()->orderBy('id');

        if ($actor->user_type === UserType::Bp) {
            $scope = $this->hierarchy->descendantIdsIncludingSelf($actor->businessPartner);
            $bpQuery->whereIn('id', $scope);
            $customerQuery->whereIn('managing_bp_id', $scope);
        }

        $rows = [];
        if (in_array($broadcast, [AnnouncementTargetType::All, AnnouncementTargetType::AllBp], true)) {
            foreach ($bpQuery->pluck('id') as $id) {
                $rows[] = ['type' => AnnouncementTargetType::Bp, 'id' => (int) $id];
            }
        }
        if (in_array($broadcast, [AnnouncementTargetType::All, AnnouncementTargetType::AllCustomer], true)) {
            foreach ($customerQuery->pluck('id') as $id) {
                $rows[] = ['type' => AnnouncementTargetType::Customer, 'id' => (int) $id];
            }
        }

        if ($rows === []) {
            throw new InvalidArgumentException('スナップショット対象の配信先がありません。');
        }

        return $rows;
    }
}
