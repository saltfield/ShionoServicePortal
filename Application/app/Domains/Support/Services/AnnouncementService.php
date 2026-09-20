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
     */
    public function publish(User $actor, string $title, string $body, array $targets): Announcement
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

        $normalized = $this->normalizeTargets($actor, $targets);

        return DB::transaction(function () use ($actor, $title, $body, $normalized) {
            $announcement = Announcement::query()->create([
                'title' => $title,
                'body' => $body,
                'published_at' => now(),
                'created_by_user_id' => $actor->id,
                'owning_bp_id' => $actor->user_type === UserType::Admin ? null : $actor->bp_id,
            ]);

            foreach ($normalized as $target) {
                AnnouncementTarget::query()->create([
                    'announcement_id' => $announcement->id,
                    'target_type' => $target['type'],
                    'target_id' => $target['id'],
                ]);
            }

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
        $query = Announcement::query()
            ->with(['targets', 'createdBy'])
            ->where('published_at', '<=', now())
            ->latest('published_at');

        if ($actor->user_type === UserType::Admin) {
            return $query;
        }

        return $query->where(function (Builder $builder) use ($actor) {
            $builder->whereHas('targets', function (Builder $targetQuery) {
                $targetQuery->where('target_type', AnnouncementTargetType::All->value);
            });

            if ($actor->user_type === UserType::Bp && $actor->bp_id) {
                $bpIds = $this->hierarchy->descendantIdsIncludingSelf($actor->businessPartner);
                $builder->orWhereHas('targets', function (Builder $targetQuery) use ($bpIds) {
                    $targetQuery->where('target_type', AnnouncementTargetType::Bp->value)
                        ->whereIn('target_id', $bpIds);
                });
            }

            if ($actor->user_type === UserType::Customer && $actor->customer_id) {
                $builder->orWhereHas('targets', function (Builder $targetQuery) use ($actor) {
                    $targetQuery->where('target_type', AnnouncementTargetType::Customer->value)
                        ->where('target_id', $actor->customer_id);
                });

                $actor->loadMissing('customer');
                if ($actor->customer?->managing_bp_id) {
                    $managingBpId = (int) $actor->customer->managing_bp_id;
                    $builder->orWhereHas('targets', function (Builder $targetQuery) use ($managingBpId) {
                        $targetQuery->where('target_type', AnnouncementTargetType::Bp->value)
                            ->where('target_id', $managingBpId);
                    });
                }
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

    /**
     * @param  list<array{type: string, id?: int|null}>  $targets
     * @return list<array{type: AnnouncementTargetType, id: int|null}>
     */
    private function normalizeTargets(User $actor, array $targets): array
    {
        $normalized = [];

        foreach ($targets as $target) {
            $type = AnnouncementTargetType::tryFrom((string) ($target['type'] ?? ''));
            if ($type === null) {
                throw new InvalidArgumentException('不正な配信先種別です。');
            }

            $id = isset($target['id']) ? (int) $target['id'] : null;

            if ($type === AnnouncementTargetType::All) {
                if ($actor->user_type !== UserType::Admin) {
                    throw new InvalidArgumentException('全体配信は管理者のみ可能です。');
                }
                $normalized[] = ['type' => $type, 'id' => null];
                continue;
            }

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
                $normalized[] = ['type' => $type, 'id' => $bp->id];
                continue;
            }

            $customer = Customer::query()->findOrFail($id);
            if ($actor->user_type === UserType::Bp) {
                $scope = $this->hierarchy->descendantIdsIncludingSelf($actor->businessPartner);
                if (! in_array($customer->managing_bp_id, $scope, true)) {
                    throw new InvalidArgumentException('配下外のカスタマーには配信できません。');
                }
            }
            $normalized[] = ['type' => $type, 'id' => $customer->id];
        }

        return $normalized;
    }
}
