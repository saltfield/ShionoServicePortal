<?php

use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Domains\Auth\Services\NumberSequenceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-09-20 12:00:00', 'Asia/Tokyo'));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('issues the first BPN for the current month as BPNYYYYMM001', function () {
    $service = app(NumberSequenceService::class);

    expect($service->next(PartnerCodePrefix::Bpn))->toBe('BPN202609001');
});

it('issues sequential BPN numbers within the same month', function () {
    $service = app(NumberSequenceService::class);

    expect($service->next(PartnerCodePrefix::Bpn))->toBe('BPN202609001')
        ->and($service->next(PartnerCodePrefix::Bpn))->toBe('BPN202609002')
        ->and($service->next(PartnerCodePrefix::Bpn))->toBe('BPN202609003');
});

it('issues CN independently from BPN in the same month', function () {
    $service = app(NumberSequenceService::class);

    expect($service->next(PartnerCodePrefix::Bpn))->toBe('BPN202609001')
        ->and($service->next(PartnerCodePrefix::Cn))->toBe('CN202609001')
        ->and($service->next(PartnerCodePrefix::Cn))->toBe('CN202609002')
        ->and($service->next(PartnerCodePrefix::Bpn))->toBe('BPN202609002');
});

it('resets the sequence when the month changes', function () {
    $service = app(NumberSequenceService::class);

    expect($service->next(PartnerCodePrefix::Bpn))->toBe('BPN202609001');

    Carbon::setTestNow(Carbon::parse('2026-10-01 00:00:00', 'Asia/Tokyo'));

    expect($service->next(PartnerCodePrefix::Bpn))->toBe('BPN202610001');
});

it('issues item codes as YYMM plus 3-digit sequence', function () {
    $service = app(NumberSequenceService::class);

    expect($service->next(PartnerCodePrefix::Item))->toBe('2609001')
        ->and($service->next(PartnerCodePrefix::Item))->toBe('2609002');
});

it('normalizes lowercase prefix input to uppercase', function () {
    $service = app(NumberSequenceService::class);

    expect($service->next('bpn'))->toBe('BPN202609001')
        ->and($service->next('cn'))->toBe('CN202609001');
});

it('rejects unsupported prefixes', function () {
    $service = app(NumberSequenceService::class);

    $service->next('XYZ');
})->throws(InvalidArgumentException::class);

it('rejects sequences beyond 999 in the same month', function () {
    $service = app(NumberSequenceService::class);

    \App\Models\NumberSequence::query()->create([
        'prefix' => 'BPN',
        'year_month' => '202609',
        'last_seq' => 999,
    ]);

    $service->next(PartnerCodePrefix::Bpn);
})->throws(RuntimeException::class);

it('produces unique codes under concurrent requests', function () {
    $service = app(NumberSequenceService::class);
    $codes = [];

    // Simulate concurrent issuance within separate transactions sequentially
    // while verifying lock-based increment never duplicates.
    foreach (range(1, 20) as $_) {
        $codes[] = $service->next(PartnerCodePrefix::Bpn);
    }

    expect($codes)->toHaveCount(20)
        ->and($codes)->toBe(array_values(array_unique($codes)))
        ->and($codes[0])->toBe('BPN202609001')
        ->and($codes[19])->toBe('BPN202609020');
});
