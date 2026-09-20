<?php

use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Domains\Auth\Enums\TwoFactorMode;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Services\NumberSequenceService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

it('allows authenticated user to change password with current password', function () {
    $user = User::factory()->admin()->create([
        'login_id' => 'ACCTPW',
        'password' => 'Password123!',
        'must_change_password' => false,
    ]);

    $this->post(route('admin.login.store'), [
        'login_id' => 'ACCTPW',
        'password' => 'Password123!',
    ]);

    $this->get(route('admin.password.edit'))
        ->assertOk()
        ->assertSee('現在のパスワード');

    $this->post(route('admin.password.update'), [
        'current_password' => 'Password123!',
        'password' => 'NewerPass123!',
        'password_confirmation' => 'NewerPass123!',
    ])->assertRedirect(route('admin.password.edit'))
        ->assertSessionHas('status');

    expect(Hash::check('NewerPass123!', $user->fresh()->password))->toBeTrue();
});

it('rejects voluntary password change with wrong current password', function () {
    User::factory()->admin()->create([
        'login_id' => 'ACCTPW2',
        'password' => 'Password123!',
        'must_change_password' => false,
    ]);

    $this->post(route('admin.login.store'), [
        'login_id' => 'ACCTPW2',
        'password' => 'Password123!',
    ]);

    $this->from(route('admin.password.edit'))
        ->post(route('admin.password.update'), [
            'current_password' => 'WrongPassword1!',
            'password' => 'NewerPass123!',
            'password_confirmation' => 'NewerPass123!',
        ])
        ->assertRedirect(route('admin.password.edit'))
        ->assertSessionHasErrors('current_password');
});

it('shows 2fa settings and enables totp for optional mode', function () {
    $user = User::factory()->admin()->create([
        'login_id' => 'ACCT2FA',
        'password' => 'Password123!',
        'must_change_password' => false,
        'two_factor_mode' => TwoFactorMode::Optional,
    ]);

    $this->post(route('admin.login.store'), [
        'login_id' => 'ACCT2FA',
        'password' => 'Password123!',
    ]);

    $this->get(route('admin.two-factor.settings'))
        ->assertOk()
        ->assertSee('二段階認証')
        ->assertSee('有効化する');

    $secret = session('auth.admin.account_2fa_secret');
    expect($secret)->not->toBeNull();

    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $this->post(route('admin.two-factor.settings.enable'), [
        'code' => $code,
    ])->assertRedirect(route('admin.two-factor.settings'))
        ->assertSessionHas('status');

    $user->refresh();
    expect($user->two_factor_confirmed_at)->not->toBeNull()
        ->and($user->two_factor_secret)->toBe($secret);

    $this->get(route('admin.two-factor.settings'))
        ->assertOk()
        ->assertSee('無効化');
});

it('allows disabling 2fa from settings when optional', function () {
    $user = User::factory()->admin()->create([
        'login_id' => 'ACCT2FADIS',
        'password' => 'Password123!',
        'must_change_password' => false,
        'two_factor_mode' => TwoFactorMode::Optional,
    ]);

    $secret = app(Google2FA::class)->generateSecretKey();
    $user->forceFill([
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => now(),
    ])->save();

    $code = app(Google2FA::class)->getCurrentOtp($secret);
    $this->post(route('admin.login.store'), [
        'login_id' => 'ACCT2FADIS',
        'password' => 'Password123!',
    ]);
    $this->post(route('admin.two-factor.challenge.store'), ['code' => $code]);

    $this->post(route('admin.two-factor.disable'))
        ->assertRedirect(route('admin.two-factor.settings'));

    expect($user->fresh()->two_factor_secret)->toBeNull();
});

it('hides enable ui when bp two factor is disabled', function () {
    $bpn = app(NumberSequenceService::class)->next(PartnerCodePrefix::Bpn);
    $partner = app(BpHierarchyService::class)->createRoot($bpn, 'No2FA BP', [
        'two_factor_mode' => TwoFactorMode::Disabled,
    ]);
    User::factory()->bp($partner)->create([
        'login_id' => 'BPNO2FA',
        'password' => 'Password123!',
        'must_change_password' => false,
    ]);

    $this->post(route('bp.login.store'), [
        'login_id' => 'BPNO2FA',
        'bpn' => $partner->code,
        'password' => 'Password123!',
    ]);

    $this->get(route('bp.two-factor.settings'))
        ->assertOk()
        ->assertSee('利用できません')
        ->assertDontSee('有効化する');
});
