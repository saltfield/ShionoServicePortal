<?php

namespace App\Http\Controllers\Concerns;

use App\Domains\Support\Enums\AnnouncementTargetType;
use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

trait HandlesAnnouncementForm
{
    /**
     * @return array{
     *     title: string,
     *     body: string,
     *     targets: list<array{type: string, id?: int|null}>,
     *     options: array{published_at: ?Carbon, expires_at: ?Carbon, include_new_registrations: bool}
     * }
     */
    protected function validatedAnnouncementPayload(Request $request, bool $allowBroadcast): array
    {
        $deliveryTypes = $allowBroadcast
            ? ['all', 'all_bp', 'all_customer', 'bp', 'customer']
            : ['all', 'all_bp', 'all_customer', 'bp', 'customer'];

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'delivery_type' => ['required', Rule::in($deliveryTypes)],
            'target_ids' => ['nullable', 'array'],
            'target_ids.*' => ['integer'],
            'include_new_registrations' => ['sometimes', 'boolean'],
            'published_mode' => ['required', Rule::in(['immediate', 'scheduled'])],
            'published_date' => ['nullable', 'date_format:Y-m-d'],
            'published_time' => ['nullable', 'date_format:H:i'],
            'expires_mode' => ['required', Rule::in(['indefinite', 'until'])],
            'expires_date' => ['nullable', 'date_format:Y-m-d'],
            'expires_time' => ['nullable', 'date_format:H:i'],
        ]);

        $deliveryType = $validated['delivery_type'];
        $targets = [];

        if (in_array($deliveryType, ['all', 'all_bp', 'all_customer'], true)) {
            $targets[] = ['type' => $deliveryType];
        } else {
            $ids = array_values(array_unique(array_map('intval', $validated['target_ids'] ?? [])));
            if ($ids === []) {
                throw ValidationException::withMessages([
                    'target_ids' => '配信先を1件以上選択してください。',
                ]);
            }
            foreach ($ids as $id) {
                $targets[] = ['type' => $deliveryType, 'id' => $id];
            }
        }

        if ($validated['published_mode'] === 'scheduled' && empty($validated['published_date'])) {
            throw ValidationException::withMessages([
                'published_date' => '公開開始日を入力してください。',
            ]);
        }
        if ($validated['expires_mode'] === 'until' && empty($validated['expires_date'])) {
            throw ValidationException::withMessages([
                'expires_date' => '公開終了日を入力してください。',
            ]);
        }

        $publishedAt = $validated['published_mode'] === 'immediate'
            ? null
            : $this->combineDateAndOptionalTime($validated['published_date'], $validated['published_time'] ?? null);

        $expiresAt = $validated['expires_mode'] === 'indefinite'
            ? null
            : $this->combineDateAndOptionalTime($validated['expires_date'], $validated['expires_time'] ?? null, endOfDayIfNoTime: true);

        $includeNew = in_array($deliveryType, ['all', 'all_bp', 'all_customer'], true)
            ? $request->boolean('include_new_registrations')
            : false;

        return [
            'title' => $validated['title'],
            'body' => $validated['body'],
            'targets' => $targets,
            'options' => [
                'published_at' => $publishedAt,
                'expires_at' => $expiresAt,
                'include_new_registrations' => $includeNew,
            ],
        ];
    }

    protected function combineDateAndOptionalTime(?string $date, ?string $time, bool $endOfDayIfNoTime = false): Carbon
    {
        $timezone = config('app.timezone');
        if ($time) {
            return Carbon::parse($date.' '.$time, $timezone);
        }

        $carbon = Carbon::parse($date.' 00:00:00', $timezone);

        return $endOfDayIfNoTime ? $carbon->endOfDay() : $carbon->startOfDay();
    }

    /**
     * @return array<string, mixed>
     */
    protected function announcementFormState(Announcement $announcement): array
    {
        $targets = $announcement->targets;
        $first = $targets->first();
        $type = $first?->target_type;

        $selectedDeliveryType = 'customer';
        $selectedTargetIds = [];
        $includeNew = (bool) $announcement->include_new_registrations;

        if ($type instanceof AnnouncementTargetType && $type->isBroadcast() && $targets->count() === 1 && $first->target_id === null) {
            $selectedDeliveryType = $type->value;
        } elseif ($targets->every(fn ($t) => $t->target_type === AnnouncementTargetType::Bp)) {
            $selectedDeliveryType = 'bp';
            $selectedTargetIds = $targets->pluck('target_id')->map(fn ($id) => (int) $id)->all();
            $includeNew = false;
        } elseif ($targets->every(fn ($t) => $t->target_type === AnnouncementTargetType::Customer)) {
            $selectedDeliveryType = 'customer';
            $selectedTargetIds = $targets->pluck('target_id')->map(fn ($id) => (int) $id)->all();
            $includeNew = false;
        } elseif ($targets->isNotEmpty()) {
            // Snapshot of broadcast without include_new: mixed bp/customer rows.
            // Prefer showing as the matching broadcast type with include_new off.
            $hasBp = $targets->contains(fn ($t) => $t->target_type === AnnouncementTargetType::Bp);
            $hasCustomer = $targets->contains(fn ($t) => $t->target_type === AnnouncementTargetType::Customer);
            if ($hasBp && $hasCustomer) {
                $selectedDeliveryType = 'all';
            } elseif ($hasBp) {
                $selectedDeliveryType = 'all_bp';
            } else {
                $selectedDeliveryType = 'all_customer';
            }
            $includeNew = false;
            $selectedTargetIds = [];
        }

        $publishedAt = $announcement->published_at?->timezone(config('app.timezone'));
        $expiresAt = $announcement->expires_at?->timezone(config('app.timezone'));
        $isImmediate = $publishedAt && $publishedAt->lte(now()->timezone(config('app.timezone'))->addMinute());

        return [
            'selectedDeliveryType' => $selectedDeliveryType,
            'selectedTargetIds' => $selectedTargetIds,
            'includeNewRegistrations' => $includeNew,
            'publishedMode' => $isImmediate ? 'immediate' : 'scheduled',
            'expiresMode' => $expiresAt ? 'until' : 'indefinite',
            'publishedDate' => $publishedAt?->format('Y-m-d') ?? '',
            'publishedTime' => ($publishedAt && ($publishedAt->format('H:i') !== '00:00')) ? $publishedAt->format('H:i') : '',
            'expiresDate' => $expiresAt?->format('Y-m-d') ?? '',
            'expiresTime' => ($expiresAt && ($expiresAt->format('H:i') !== '23:59')) ? $expiresAt->format('H:i') : '',
        ];
    }
}
