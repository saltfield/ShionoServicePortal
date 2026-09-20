<?php

use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Domains\Auth\Enums\TwoFactorMode;
use App\Domains\Auth\Enums\UserType;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Services\NumberSequenceService;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

function makeAdmin(array $overrides = []): User
{
    return User::factory()->admin()->create(array_merge([
        'login_id' => 'ADMIN2FA',
        'password' => 'Password123!',
        'is_active' => true,
        'two_factor_mode' => null,
    ], $overrides));
}

function makeBpContext(TwoFactorMode $bpMode = TwoFactorMode::Optional, array $userOverrides = []): array
{
    $bpn = app(NumberSequenceService::class)->next(PartnerCodePrefix::Bpn);
    $partner = app(BpHierarchyService::class)->createRoot($bpn, '2FA BP', [
        'two_factor_mode' => $bpMode,
    ]);

    $user = User::factory()->bp($partner)->create(array_merge([
        'login_id' => 'BP2FA001',
        'password' => 'Password123!',
        'is_active' => true,
    ], $userOverrides));

    return [$user, $partner];
}

function enableTotp(User $user): string
{
    $google2fa = app(Google2FA::class);
    $secret = $google2fa->generateSecretKey();

    $user->forceFill([
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $secret;
}

it('skips totp when effective mode is optional and totp is not enabled', function () {
    makeAdmin();

    $this->post(route('admin.login.store'), [
        'login_id' => 'ADMIN2FA',
        'password' => 'Password123!',
    ])->assertRedirect(route('admin.dashboard'));

    $this->assertAuthenticated('admin');
});

it('requires totp challenge when optional user has totp enabled', function () {
    $user = makeAdmin();
    $secret = enableTotp($user);

    $this->post(route('admin.login.store'), [
        'login_id' => 'ADMIN2FA',
        'password' => 'Password123!',
    ])->assertRedirect(route('admin.two-factor.challenge'));

    $this->assertGuest('admin');

    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $this->post(route('admin.two-factor.challenge.store'), [
        'code' => $code,
    ])->assertRedirect(route('admin.dashboard'));

    $this->assertAuthenticated('admin');
});

it('forces setup when bp mode is forced and totp is not configured', function () {
    makeBpContext(TwoFactorMode::Forced);

    $this->post(route('bp.login.store'), [
        'bpn' => \App\Models\BusinessPartner::first()->code,
        'login_id' => 'BP2FA001',
        'password' => 'Password123!',
    ])->assertRedirect(route('bp.two-factor.setup'));

    $this->assertGuest('bp');
});

it('skips totp entirely when bp mode is disabled even if secret exists', function () {
    [$user, $partner] = makeBpContext(TwoFactorMode::Disabled);
    enableTotp($user);

    $this->post(route('bp.login.store'), [
        'bpn' => $partner->code,
        'login_id' => 'BP2FA001',
        'password' => 'Password123!',
    ])->assertRedirect(route('bp.dashboard'));

    $this->assertAuthenticated('bp');
    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();
});

it('skips totp when admin emergency forced disable is on', function () {
    $user = makeAdmin(['two_factor_forced_disabled' => true]);
    enableTotp($user);

    $this->post(route('admin.login.store'), [
        'login_id' => 'ADMIN2FA',
        'password' => 'Password123!',
    ])->assertRedirect(route('admin.dashboard'));

    $this->assertAuthenticated('admin');
});

it('allows self disable when mode is optional', function () {
    $user = makeAdmin();
    enableTotp($user);

    $code = app(Google2FA::class)->getCurrentOtp($user->fresh()->two_factor_secret);

    $this->post(route('admin.login.store'), [
        'login_id' => 'ADMIN2FA',
        'password' => 'Password123!',
    ]);
    $this->post(route('admin.two-factor.challenge.store'), ['code' => $code]);

    $this->post(route('admin.two-factor.disable'))
        ->assertRedirect();

    $user->refresh();
    expect($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull();
});

it('rejects self disable when bp mode is forced', function () {
    [$user, $partner] = makeBpContext(TwoFactorMode::Forced);
    $secret = enableTotp($user);
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $this->post(route('bp.login.store'), [
        'bpn' => $partner->code,
        'login_id' => 'BP2FA001',
        'password' => 'Password123!',
    ]);
    $this->post(route('bp.two-factor.challenge.store'), ['code' => $code]);

    $this->from(route('bp.dashboard'))
        ->post(route('bp.two-factor.disable'))
        ->assertRedirect()
        ->assertSessionHasErrors('two_factor');

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();
});

it('uses customer two_factor_mode for customer users', function () {
    $bpn = app(NumberSequenceService::class)->next(PartnerCodePrefix::Bpn);
    $partner = app(BpHierarchyService::class)->createRoot($bpn, 'Parent', [
        'two_factor_mode' => TwoFactorMode::Optional,
    ]);
    $cn = app(NumberSequenceService::class)->next(PartnerCodePrefix::Cn);
    $customer = Customer::factory()->create([
        'code' => $cn,
        'managing_bp_id' => $partner->id,
        'two_factor_mode' => TwoFactorMode::Forced,
    ]);
    User::factory()->customer($customer)->create([
        'login_id' => 'CUS2FA001',
        'password' => 'Password123!',
    ]);

    $this->post(route('customer.login.store'), [
        'cn' => $cn,
        'login_id' => 'CUS2FA001',
        'password' => 'Password123!',
    ])->assertRedirect(route('customer.two-factor.setup'));
});
