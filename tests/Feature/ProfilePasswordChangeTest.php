<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfilePasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_password_change_persists_new_password_and_invalidates_old_one(): void
    {
        $oldPassword = 'OldPassword123!';
        $newPassword = 'NewPassword456!';

        $user = User::create([
            'name' => 'Password Test',
            'email' => 'password-test@example.com',
            'password' => $oldPassword,
        ]);

        Sanctum::actingAs($user);

        $this->patchJson('/api/profile/password', [
            'current_password' => $oldPassword,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])->assertOk();

        $user->refresh();

        $this->assertTrue(Hash::check($newPassword, $user->password));
        $this->assertFalse(Hash::check($oldPassword, $user->password));
    }

    public function test_profile_password_change_rejects_wrong_current_password(): void
    {
        $user = User::create([
            'name' => 'Password Test',
            'email' => 'password-test-2@example.com',
            'password' => 'CorrectPassword123!',
        ]);

        Sanctum::actingAs($user);

        $this->patchJson('/api/profile/password', [
            'current_password' => 'WrongPassword123!',
            'password' => 'NewPassword456!',
            'password_confirmation' => 'NewPassword456!',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $user->refresh();
        $this->assertTrue(Hash::check('CorrectPassword123!', $user->password));
    }
}
