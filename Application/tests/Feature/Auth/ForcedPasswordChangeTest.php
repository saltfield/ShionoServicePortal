<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('redirects to password change when must_change_password is true', function () {
    User::factory()->admin()->create([
        'login_id' => 'ADMINPW',
        'password' => 'Password123!',
        'must_change_password' => true,
    ]);

    $this->post(route('admin.login.store'), [
        'login_id' => 'ADMINPW',
        'password' => 'Password123!',
    ])->assertRedirect(route('admin.password.edit'));

    $this->assertAuthenticated('admin');
});

it('blocks dashboard until password is changed', function () {
    User::factory()->admin()->create([
        'login_id' => 'ADMINPW',
        'password' => 'Password123!',
        'must_change_password' => true,
    ]);

    $this->post(route('admin.login.store'), [
        'login_id' => 'ADMINPW',
        'password' => 'Password123!',
    ]);

    $this->get(route('admin.dashboard'))
        ->assertRedirect(route('admin.password.edit'));
});

it('updates password and clears must_change_password flag', function () {
    $user = User::factory()->admin()->create([
        'login_id' => 'ADMINPW',
        'password' => 'Password123!',
        'must_change_password' => true,
    ]);

    $this->post(route('admin.login.store'), [
        'login_id' => 'ADMINPW',
        'password' => 'Password123!',
    ]);

    $this->post(route('admin.password.update'), [
        'password' => 'NewPassword123!',
        'password_confirmation' => 'NewPassword123!',
    ])->assertRedirect(route('admin.dashboard'));

    $user->refresh();

    expect($user->must_change_password)->toBeFalse()
        ->and(Hash::check('NewPassword123!', $user->password))->toBeTrue()
        ->and($user->password_changed_at)->not->toBeNull();

    $this->get(route('admin.dashboard'))->assertOk();
});

it('rejects password change when confirmation does not match', function () {
    User::factory()->admin()->create([
        'login_id' => 'ADMINPW',
        'password' => 'Password123!',
        'must_change_password' => true,
    ]);

    $this->post(route('admin.login.store'), [
        'login_id' => 'ADMINPW',
        'password' => 'Password123!',
    ]);

    $this->from(route('admin.password.edit'))
        ->post(route('admin.password.update'), [
            'password' => 'NewPassword123!',
            'password_confirmation' => 'Mismatch123!',
        ])
        ->assertRedirect(route('admin.password.edit'))
        ->assertSessionHasErrors('password');
});

it('rejects reusing the current password', function () {
    User::factory()->admin()->create([
        'login_id' => 'ADMINPW',
        'password' => 'Password123!',
        'must_change_password' => true,
    ]);

    $this->post(route('admin.login.store'), [
        'login_id' => 'ADMINPW',
        'password' => 'Password123!',
    ]);

    $this->from(route('admin.password.edit'))
        ->post(route('admin.password.update'), [
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])
        ->assertRedirect(route('admin.password.edit'))
        ->assertSessionHasErrors('password');
});
