<?php

namespace App\Domains\Auth\Services;

use App\Domains\Auth\Enums\TwoFactorMode;
use App\Models\BusinessPartner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BpHierarchyService
{
    private const MAX_DEPTH = 5;

    public function createRoot(string $code, string $name, array $attributes = []): BusinessPartner
    {
        return DB::transaction(function () use ($code, $name, $attributes) {
            $partner = BusinessPartner::query()->create($this->attributesForCreate(
                $attributes,
                $code,
                $name,
                parentId: null,
                depth: 1,
            ));

            $this->insertSelfClosure($partner);

            return $partner;
        });
    }

    public function createChild(BusinessPartner $parent, string $code, string $name, array $attributes = []): BusinessPartner
    {
        if ($parent->depth >= self::MAX_DEPTH) {
            throw new InvalidArgumentException('Business partner hierarchy cannot exceed 5 levels.');
        }

        return DB::transaction(function () use ($parent, $code, $name, $attributes) {
            $partner = BusinessPartner::query()->create($this->attributesForCreate(
                $attributes,
                $code,
                $name,
                parentId: $parent->id,
                depth: $parent->depth + 1,
            ));

            $this->insertSelfClosure($partner);

            DB::table('bp_closure')->insertUsing(
                ['ancestor_id', 'descendant_id', 'depth_diff'],
                DB::table('bp_closure')
                    ->selectRaw('ancestor_id, ? as descendant_id, depth_diff + 1 as depth_diff', [$partner->id])
                    ->where('descendant_id', $parent->id)
            );

            return $partner->fresh();
        });
    }

    /**
     * @param  array{name?: string, is_active?: bool, two_factor_mode?: TwoFactorMode|string, postal_code?: ?string, address?: ?string, building_name?: ?string, phone?: ?string, email?: ?string}  $attributes
     */
    public function update(BusinessPartner $partner, array $attributes): BusinessPartner
    {
        $allowed = collect($attributes)->only([
            'name',
            'is_active',
            'two_factor_mode',
            'postal_code',
            'address',
            'building_name',
            'phone',
            'email',
        ]);

        if ($allowed->isEmpty()) {
            return $partner;
        }

        if ($allowed->has('two_factor_mode') && is_string($allowed['two_factor_mode'])) {
            $allowed['two_factor_mode'] = TwoFactorMode::from($allowed['two_factor_mode']);
        }

        $partner->fill($allowed->all())->save();

        return $partner->fresh();
    }

    public function delete(BusinessPartner $partner): void
    {
        if ($partner->children()->exists()) {
            throw new InvalidArgumentException('配下にBPが存在するため削除できません。');
        }

        if ($partner->customers()->exists()) {
            throw new InvalidArgumentException('配下にカスタマーが存在するため削除できません。');
        }

        if ($partner->users()->exists()) {
            throw new InvalidArgumentException('所属ユーザーが存在するため削除できません。');
        }

        DB::transaction(function () use ($partner) {
            DB::table('bp_closure')
                ->where('ancestor_id', $partner->id)
                ->orWhere('descendant_id', $partner->id)
                ->delete();

            $partner->delete();
        });
    }

    /**
     * Move a subtree under a new parent (or to root when $newParent is null).
     */
    public function move(BusinessPartner $node, ?BusinessPartner $newParent): BusinessPartner
    {
        if ($newParent !== null && $newParent->id === $node->id) {
            throw new InvalidArgumentException('自分自身の配下には移動できません。');
        }

        if ($newParent !== null && $this->isDescendant($node, $newParent)) {
            throw new InvalidArgumentException('配下のBPへは移動できません。');
        }

        if (($newParent?->id ?? null) === $node->parent_id) {
            return $node;
        }

        $subtreeHeight = $this->subtreeHeight($node);
        $newDepth = $newParent === null ? 1 : $newParent->depth + 1;

        if ($newDepth + $subtreeHeight - 1 > self::MAX_DEPTH) {
            throw new InvalidArgumentException('Business partner hierarchy cannot exceed 5 levels.');
        }

        return DB::transaction(function () use ($node, $newParent, $newDepth) {
            $subtreeIds = $this->descendantIdsIncludingSelf($node);

            DB::table('bp_closure')
                ->whereIn('descendant_id', $subtreeIds)
                ->whereNotIn('ancestor_id', $subtreeIds)
                ->delete();

            $depthDelta = $newDepth - $node->depth;

            $node->forceFill([
                'parent_id' => $newParent?->id,
                'depth' => $newDepth,
            ])->save();

            if ($depthDelta !== 0) {
                BusinessPartner::query()
                    ->whereIn('id', $subtreeIds)
                    ->where('id', '!=', $node->id)
                    ->update([
                        'depth' => DB::raw('depth + ('.$depthDelta.')'),
                    ]);
            }

            if ($newParent !== null) {
                $rows = DB::table('bp_closure as a')
                    ->join('bp_closure as d', function ($join) use ($node) {
                        $join->where('d.ancestor_id', $node->id);
                    })
                    ->where('a.descendant_id', $newParent->id)
                    ->selectRaw('a.ancestor_id, d.descendant_id, a.depth_diff + d.depth_diff + 1 as depth_diff')
                    ->get();

                foreach ($rows->chunk(500) as $chunk) {
                    DB::table('bp_closure')->insert($chunk->map(fn ($row) => [
                        'ancestor_id' => $row->ancestor_id,
                        'descendant_id' => $row->descendant_id,
                        'depth_diff' => $row->depth_diff,
                    ])->all());
                }
            }

            return $node->fresh();
        });
    }

    public function descendants(BusinessPartner $partner, bool $includeSelf = false): Collection
    {
        $query = DB::table('bp_closure')
            ->join('business_partners', 'business_partners.id', '=', 'bp_closure.descendant_id')
            ->where('bp_closure.ancestor_id', $partner->id)
            ->whereNull('business_partners.deleted_at')
            ->when(! $includeSelf, fn ($q) => $q->where('bp_closure.depth_diff', '>', 0))
            ->orderBy('bp_closure.depth_diff')
            ->orderBy('business_partners.code')
            ->select('business_partners.*');

        return BusinessPartner::query()->hydrate($query->get()->all());
    }

    public function ancestors(BusinessPartner $partner, bool $includeSelf = false): Collection
    {
        $query = DB::table('bp_closure')
            ->join('business_partners', 'business_partners.id', '=', 'bp_closure.ancestor_id')
            ->where('bp_closure.descendant_id', $partner->id)
            ->whereNull('business_partners.deleted_at')
            ->when(! $includeSelf, fn ($q) => $q->where('bp_closure.depth_diff', '>', 0))
            ->orderBy('bp_closure.depth_diff')
            ->select('business_partners.*');

        return BusinessPartner::query()->hydrate($query->get()->all());
    }

    public function isDescendant(BusinessPartner $ancestor, BusinessPartner $possibleDescendant): bool
    {
        return DB::table('bp_closure')
            ->where('ancestor_id', $ancestor->id)
            ->where('descendant_id', $possibleDescendant->id)
            ->where('depth_diff', '>', 0)
            ->exists();
    }

    public function isSelfOrDescendant(BusinessPartner $ancestor, BusinessPartner $possibleDescendant): bool
    {
        return $ancestor->id === $possibleDescendant->id
            || $this->isDescendant($ancestor, $possibleDescendant);
    }

    public function rootOf(BusinessPartner $partner): BusinessPartner
    {
        $ancestors = $this->ancestors($partner, includeSelf: true);

        return $ancestors->last() ?? $partner;
    }

    /**
     * 管理BPからルートまでの親子区間（子→親方向の並びで、seller=親 / buyer=子）。
     *
     * @return list<array{seller: BusinessPartner, buyer: BusinessPartner, depth_from_root: int}>
     */
    public function wholesaleEdgesFromLeaf(BusinessPartner $leaf): array
    {
        $chain = $this->ancestors($leaf, includeSelf: true)->values();
        // depth_diff ascending: leaf(0), parent(1), ... root(n)
        $edges = [];
        for ($i = 0; $i < $chain->count() - 1; $i++) {
            $buyer = $chain[$i];
            $seller = $chain[$i + 1];
            $edges[] = [
                'seller' => $seller,
                'buyer' => $buyer,
                'depth_from_root' => $chain->count() - 2 - $i,
            ];
        }

        return $edges;
    }

    /**
     * @return list<int>
     */
    public function descendantIdsIncludingSelf(BusinessPartner $partner): array
    {
        return DB::table('bp_closure')
            ->where('ancestor_id', $partner->id)
            ->pluck('descendant_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function subtreeHeight(BusinessPartner $partner): int
    {
        $maxDiff = (int) DB::table('bp_closure')
            ->where('ancestor_id', $partner->id)
            ->max('depth_diff');

        return $maxDiff + 1;
    }

    private function attributesForCreate(array $attributes, string $code, string $name, ?int $parentId, int $depth): array
    {
        return array_merge([
            'two_factor_mode' => TwoFactorMode::Optional,
            'is_active' => true,
        ], $attributes, [
            'code' => $code,
            'name' => $name,
            'parent_id' => $parentId,
            'depth' => $depth,
        ]);
    }

    private function insertSelfClosure(BusinessPartner $partner): void
    {
        DB::table('bp_closure')->insert([
            'ancestor_id' => $partner->id,
            'descendant_id' => $partner->id,
            'depth_diff' => 0,
        ]);
    }
}
