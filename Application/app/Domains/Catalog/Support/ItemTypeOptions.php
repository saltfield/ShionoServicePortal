<?php

namespace App\Domains\Catalog\Support;

use App\Models\ItemType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ItemTypeOptions
{
    /**
     * @return Collection<int, ItemType>
     */
    public static function selectable(?int $owningBpId = null, ?int $includeTypeId = null): Collection
    {
        return self::selectableForOwnerIds(
            $owningBpId !== null ? [$owningBpId] : [],
            $includeTypeId
        );
    }

    /**
     * @param  list<int>  $owningBpIds
     * @return Collection<int, ItemType>
     */
    public static function selectableForOwnerIds(array $owningBpIds, ?int $includeTypeId = null): Collection
    {
        return self::queryForOwnerIds($owningBpIds, $includeTypeId)->orderBy('name')->get();
    }

    /**
     * @return Builder<ItemType>
     */
    public static function query(?int $owningBpId = null, ?int $includeTypeId = null): Builder
    {
        return self::queryForOwnerIds(
            $owningBpId !== null ? [$owningBpId] : [],
            $includeTypeId
        );
    }

    /**
     * @param  list<int>  $owningBpIds
     * @return Builder<ItemType>
     */
    public static function queryForOwnerIds(array $owningBpIds, ?int $includeTypeId = null): Builder
    {
        $ids = array_values(array_unique(array_map('intval', $owningBpIds)));

        return ItemType::query()
            ->where(function (Builder $query) use ($ids) {
                $query->whereNull('owning_bp_id');
                if ($ids !== []) {
                    $query->orWhereIn('owning_bp_id', $ids);
                }
            })
            ->where(function (Builder $query) use ($includeTypeId) {
                $query->where('is_active', true);
                if ($includeTypeId !== null) {
                    // Eloquent Builder に orWhereKey は無いため id で OR する
                    $query->orWhere($query->getModel()->getQualifiedKeyName(), $includeTypeId);
                }
            });
    }

    public static function assertAssignable(int $itemTypeId, ?int $owningBpId = null): void
    {
        $type = ItemType::query()->find($itemTypeId);
        if ($type === null || ! $type->is_active) {
            throw new \InvalidArgumentException('有効な品目種別を選択してください。');
        }

        if ($type->owning_bp_id === null) {
            return;
        }

        if ($owningBpId === null || (int) $type->owning_bp_id !== (int) $owningBpId) {
            throw new \InvalidArgumentException('この品目種別は選択できません。');
        }
    }
}
