<?php

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Enums\BillingBatchDayMode;
use App\Models\SystemSetting;
use Illuminate\Support\Carbon;

class BillingScheduleService
{
    public const SETTING_KEY = 'billing_batch_schedule';

    /**
     * @return array{enabled: bool, day_mode: string, day_of_month: int|null, time: string, timezone: string}
     */
    public function getSchedule(): array
    {
        $defaults = [
            'enabled' => true,
            'day_mode' => BillingBatchDayMode::MonthEnd->value,
            'day_of_month' => null,
            'time' => '10:00',
            'timezone' => 'Asia/Tokyo',
        ];

        $value = SystemSetting::getValue(self::SETTING_KEY, $defaults);

        return array_merge($defaults, is_array($value) ? $value : []);
    }

    /**
     * @param  array{enabled?: bool, day_mode?: string, day_of_month?: int|null, time?: string, timezone?: string}  $data
     * @return array{enabled: bool, day_mode: string, day_of_month: int|null, time: string, timezone: string}
     */
    public function updateSchedule(array $data): array
    {
        $current = $this->getSchedule();
        $merged = array_merge($current, array_intersect_key($data, $current));

        $mode = BillingBatchDayMode::from($merged['day_mode']);
        if ($mode === BillingBatchDayMode::DayOfMonth) {
            $day = (int) ($merged['day_of_month'] ?? 0);
            if ($day < 1 || $day > 31) {
                throw new \InvalidArgumentException('毎月N日は1〜31で指定してください。');
            }
            $merged['day_of_month'] = $day;
        } else {
            $merged['day_of_month'] = null;
        }

        if (! preg_match('/^\d{2}:\d{2}$/', (string) $merged['time'])) {
            throw new \InvalidArgumentException('時刻は HH:MM 形式で指定してください。');
        }

        SystemSetting::putValue(self::SETTING_KEY, $merged);

        return $this->getSchedule();
    }

    /**
     * 実行対象日かつ、設定時刻以降（当日中の取りこぼし回収を含む）。
     */
    public function shouldRunAt(Carbon $now): bool
    {
        $schedule = $this->getSchedule();
        if (! $schedule['enabled']) {
            return false;
        }

        $tz = $schedule['timezone'] ?: 'Asia/Tokyo';
        $local = $now->copy()->timezone($tz);

        if (! $this->isScheduledDay($local, $schedule)) {
            return false;
        }

        $scheduledAt = $this->scheduledAtOn($local, $schedule);

        return $local->greaterThanOrEqualTo($scheduledAt);
    }

    /**
     * @return array{enabled: bool, day_mode: string, day_of_month: int|null, time: string, timezone: string, next_run_at: string|null, next_run_label: string, is_due_today: bool}
     */
    public function getScheduleWithNextRun(?Carbon $now = null): array
    {
        $schedule = $this->getSchedule();
        $now ??= now();
        $tz = $schedule['timezone'] ?: 'Asia/Tokyo';
        $local = $now->copy()->timezone($tz);

        $isDueToday = $schedule['enabled']
            && $this->isScheduledDay($local, $schedule)
            && $local->greaterThanOrEqualTo($this->scheduledAtOn($local, $schedule));

        if ($isDueToday) {
            $dueAt = $this->scheduledAtOn($local, $schedule);

            return [
                ...$schedule,
                'next_run_at' => $dueAt->toDateTimeString(),
                'next_run_label' => $dueAt->format('Y-m-d H:i').'（'.$tz.'・実行待ち）',
                'is_due_today' => true,
            ];
        }

        $next = $this->nextRunAt($local);

        return [
            ...$schedule,
            'next_run_at' => $next?->toDateTimeString(),
            'next_run_label' => $next
                ? $next->timezone($tz)->format('Y-m-d H:i').'（'.$tz.'）'
                : '—',
            'is_due_today' => false,
        ];
    }

    public function nextRunAt(?Carbon $now = null): ?Carbon
    {
        $schedule = $this->getSchedule();
        if (! $schedule['enabled']) {
            return null;
        }

        $tz = $schedule['timezone'] ?: 'Asia/Tokyo';
        $cursor = ($now ?? now())->copy()->timezone($tz)->startOfMinute();

        for ($i = 0; $i < 400; $i++) {
            $day = $cursor->copy()->startOfDay();
            if ($this->isScheduledDay($day, $schedule)) {
                $candidate = $this->scheduledAtOn($day, $schedule);
                if ($candidate->greaterThanOrEqualTo($cursor)) {
                    return $candidate;
                }
            }
            $cursor = $cursor->copy()->addDay()->startOfDay();
        }

        return null;
    }

    public function billingYearMonthFor(Carbon $now): string
    {
        $schedule = $this->getSchedule();
        $tz = $schedule['timezone'] ?: 'Asia/Tokyo';

        return $now->copy()->timezone($tz)->format('Ym');
    }

    /**
     * @param  array{day_mode: string, day_of_month: int|null}  $schedule
     */
    private function isScheduledDay(Carbon $local, array $schedule): bool
    {
        $mode = BillingBatchDayMode::from($schedule['day_mode']);
        if ($mode === BillingBatchDayMode::MonthEnd) {
            return $local->isLastOfMonth();
        }

        $day = (int) ($schedule['day_of_month'] ?? 0);
        $targetDay = min($day, $local->daysInMonth);

        return (int) $local->format('j') === $targetDay;
    }

    /**
     * @param  array{time: string}  $schedule
     */
    private function scheduledAtOn(Carbon $local, array $schedule): Carbon
    {
        [$hour, $minute] = array_map('intval', explode(':', $schedule['time']));

        return $local->copy()->timezone($schedule['timezone'] ?? 'Asia/Tokyo')->setTime($hour, $minute, 0);
    }
}
