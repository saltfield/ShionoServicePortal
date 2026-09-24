<?php

namespace App\Domains\Catalog\Support;

use App\Domains\Auth\Enums\UserType;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Models\BusinessPartner;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class OrderCatalogScope
{
    /**
     * オーダー作成で選択可能な独自品目・品目種別の owning_bp_id 一覧。
     * 標準（null）は別途許可する。
     *
     * @return list<int>
     */
    public static function ownerBpIds(User $actor, BusinessPartner $managingBp, BpHierarchyService $hierarchy): array
    {
        if ($actor->user_type === UserType::Admin) {
            return $hierarchy->ancestors($managingBp, includeSelf: true)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        }

        $ids = [(int) $managingBp->id];

        if ($actor->user_type === UserType::Bp && $actor->bp_id) {
            $actorBp = $actor->businessPartner;
            if ($actorBp && $hierarchy->isSelfOrDescendant($actorBp, $managingBp)) {
                $ids[] = (int) $actorBp->id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<int>  $ownerBpIds
     * @return Builder<Item>
     */
    public static function itemsQuery(array $ownerBpIds): Builder
    {
        return Item::query()
            ->where('is_active', true)
            ->where(function (Builder $query) use ($ownerBpIds) {
                $query->whereNull('owning_bp_id');
                if ($ownerBpIds !== []) {
                    $query->orWhereIn('owning_bp_id', $ownerBpIds);
                }
            });
    }

    public static function itemAllowed(Item $item, array $ownerBpIds): bool
    {
        if ($item->owning_bp_id === null) {
            return true;
        }

        return in_array((int) $item->owning_bp_id, array_map('intval', $ownerBpIds), true);
    }
}
