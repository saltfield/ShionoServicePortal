<?php

namespace App\Domains\Auth\Services;

use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Models\NumberSequence;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class NumberSequenceService
{
    private const MAX_SEQ = 999;

    /**
     * Issue the next code for the given prefix (BPN / CN / CTR / ITEM / INV / TKT).
     *
     * Format: {PREFIX}{YYYYMM}{3-digit sequence} e.g. BPN202609001 / TKT202609001
     * Item only: {YYMM}{3-digit sequence} e.g. 2609001 (7 digits, no prefix)
     */
    public function next(PartnerCodePrefix|string $prefix, ?CarbonInterface $at = null): string
    {
        $prefixEnum = $prefix instanceof PartnerCodePrefix
            ? $prefix
            : PartnerCodePrefix::tryFromNormalized($prefix);

        if ($prefixEnum === null) {
            throw new InvalidArgumentException('Unsupported number sequence prefix.');
        }

        $prefixValue = $prefixEnum->value;
        $yearMonth = ($at ?? now())->timezone(config('app.timezone'))->format('Ym');

        return DB::transaction(function () use ($prefixEnum, $prefixValue, $yearMonth) {
            $sequence = $this->lockOrCreate($prefixValue, $yearMonth);

            if ($sequence->last_seq >= self::MAX_SEQ) {
                throw new RuntimeException(
                    "Number sequence exhausted for {$prefixValue}{$yearMonth}."
                );
            }

            $sequence->last_seq++;
            $sequence->save();

            if ($prefixEnum === PartnerCodePrefix::Item) {
                return sprintf('%s%03d', substr($yearMonth, 2), $sequence->last_seq);
            }

            return sprintf('%s%s%03d', $prefixValue, $yearMonth, $sequence->last_seq);
        });
    }

    private function lockOrCreate(string $prefix, string $yearMonth): NumberSequence
    {
        $sequence = NumberSequence::query()
            ->where('prefix', $prefix)
            ->where('year_month', $yearMonth)
            ->lockForUpdate()
            ->first();

        if ($sequence !== null) {
            return $sequence;
        }

        try {
            return NumberSequence::query()->create([
                'prefix' => $prefix,
                'year_month' => $yearMonth,
                'last_seq' => 0,
            ]);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            return NumberSequence::query()
                ->where('prefix', $prefix)
                ->where('year_month', $yearMonth)
                ->lockForUpdate()
                ->firstOrFail();
        }
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $errorCode = $exception->errorInfo[1] ?? null;

        // MySQL / MariaDB duplicate key
        return $errorCode === 1062;
    }
}
