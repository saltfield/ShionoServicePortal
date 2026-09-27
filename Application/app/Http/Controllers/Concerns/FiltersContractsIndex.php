<?php

namespace App\Http\Controllers\Concerns;

use App\Domains\Auth\Support\IdentifierNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

trait FiltersContractsIndex
{
    /**
     * @return array{
     *     contract_code: string,
     *     bpn: string,
     *     bp_name: string,
     *     cn: string,
     *     customer_name: string,
     *     item_code: string,
     *     item_name: string,
     *     data_name: string,
     *     match: 'and'|'or'
     * }
     */
    protected function contractIndexFilters(Request $request): array
    {
        $match = strtolower(trim((string) $request->input('match', 'and')));

        return [
            'contract_code' => trim((string) $request->input('contract_code', '')),
            'bpn' => trim((string) $request->input('bpn', '')),
            'bp_name' => trim((string) $request->input('bp_name', '')),
            'cn' => trim((string) $request->input('cn', '')),
            'customer_name' => trim((string) $request->input('customer_name', '')),
            'item_code' => trim((string) $request->input('item_code', '')),
            'item_name' => trim((string) $request->input('item_name', '')),
            'data_name' => trim((string) $request->input('data_name', '')),
            'match' => in_array($match, ['and', 'or'], true) ? $match : 'and',
        ];
    }

    /**
     * @param  array{
     *     contract_code: string,
     *     bpn: string,
     *     bp_name: string,
     *     cn: string,
     *     customer_name: string,
     *     item_code: string,
     *     item_name: string,
     *     data_name: string,
     *     match: 'and'|'or'
     * }  $filters
     */
    protected function applyContractIndexFilters(Builder $query, array $filters): Builder
    {
        $conditions = [];

        if ($filters['contract_code'] !== '') {
            $pattern = $this->contractSearchLikePattern($filters['contract_code'], normalize: true);
            $conditions[] = fn (Builder $q) => $q->where('code', 'like', $pattern);
        }

        if ($filters['bpn'] !== '') {
            $pattern = $this->contractSearchLikePattern($filters['bpn'], normalize: true);
            $conditions[] = fn (Builder $q) => $q->whereHas(
                'owningBp',
                fn (Builder $bp) => $bp->where('code', 'like', $pattern)
            );
        }

        if ($filters['bp_name'] !== '') {
            $pattern = $this->contractSearchLikePattern($filters['bp_name']);
            $conditions[] = fn (Builder $q) => $q->whereHas(
                'owningBp',
                fn (Builder $bp) => $bp->where('name', 'like', $pattern)
            );
        }

        if ($filters['cn'] !== '') {
            $pattern = $this->contractSearchLikePattern($filters['cn'], normalize: true);
            $conditions[] = fn (Builder $q) => $q->whereHas(
                'customer',
                fn (Builder $customer) => $customer->where('code', 'like', $pattern)
            );
        }

        if ($filters['customer_name'] !== '') {
            $pattern = $this->contractSearchLikePattern($filters['customer_name']);
            $conditions[] = fn (Builder $q) => $q->whereHas(
                'customer',
                fn (Builder $customer) => $customer->where('name', 'like', $pattern)
            );
        }

        if ($filters['item_code'] !== '') {
            $pattern = $this->contractSearchLikePattern($filters['item_code'], normalize: true);
            $conditions[] = fn (Builder $q) => $q->whereHas(
                'items.item',
                fn (Builder $item) => $item->where('code', 'like', $pattern)
            );
        }

        if ($filters['item_name'] !== '') {
            $pattern = $this->contractSearchLikePattern($filters['item_name']);
            $conditions[] = fn (Builder $q) => $q->whereHas(
                'items.item',
                fn (Builder $item) => $item->where('name', 'like', $pattern)
            );
        }

        if ($filters['data_name'] !== '') {
            $pattern = $this->contractSearchLikePattern($filters['data_name']);
            $conditions[] = fn (Builder $q) => $q->where(function (Builder $inner) use ($pattern) {
                $inner->whereHas('dataRows', fn (Builder $row) => $row->where('name', 'like', $pattern))
                    ->orWhereHas('items.dataRows', fn (Builder $row) => $row->where('name', 'like', $pattern));
            });
        }

        if ($conditions === []) {
            return $query;
        }

        $isOr = $filters['match'] === 'or';

        return $query->where(function (Builder $group) use ($conditions, $isOr) {
            foreach ($conditions as $index => $condition) {
                if ($index === 0) {
                    $group->where($condition);
                } elseif ($isOr) {
                    $group->orWhere($condition);
                } else {
                    $group->where($condition);
                }
            }
        });
    }

    /**
     * @param  array{
     *     contract_code: string,
     *     bpn: string,
     *     bp_name: string,
     *     cn: string,
     *     customer_name: string,
     *     item_code: string,
     *     item_name: string,
     *     data_name: string,
     *     match: 'and'|'or'
     * }  $filters
     * @return array<string, string>
     */
    protected function contractIndexFilterQuery(array $filters): array
    {
        $query = [];
        foreach ($filters as $key => $value) {
            if ($key === 'match') {
                if ($value === 'or') {
                    $query['match'] = 'or';
                }

                continue;
            }

            if ($value !== '') {
                $query[$key] = $value;
            }
        }

        return $query;
    }

    protected function contractSearchLikePattern(string $input, bool $normalize = false): string
    {
        $value = $normalize ? IdentifierNormalizer::normalize($input) : $input;
        $hasWildcard = str_contains($value, '*');
        $escaped = str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $value
        );

        if ($hasWildcard) {
            return str_replace('*', '%', $escaped);
        }

        return '%'.$escaped.'%';
    }
}
