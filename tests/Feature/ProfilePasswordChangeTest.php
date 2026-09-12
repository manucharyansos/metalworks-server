<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Profile\ProfileController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProfilePasswordChangeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->unsignedBigInteger('role_id')->nullable();
            $table->unsignedBigInteger('factory_id')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    public function test_profile_password_change_persists_new_password_and_invalidates_old_one(): void
    {
        $oldPassword = 'OldPassword123!';
        $newPassword = 'NewPassword456!';
        $user = $this->makeUser('password-test@example.com', $oldPassword);

        $response = $this->callPasswordController($user, [
            'current_password' => $oldPassword,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ]);

        $this->assertSame(200, $response->getStatusCode());

        $user->refresh();
        $this->assertTrue(Hash::check($newPassword, $user->password));
        $this->assertFalse(Hash::check($oldPassword, $user->password));
    }

    public function test_profile_password_change_rejects_wrong_current_password(): void
    {
        $oldPassword = 'CorrectPassword123!';
        $user = $this->makeUser('password-test-2@example.com', $oldPassword);

        $response = $this->callPasswordController($user, [
            'current_password' => 'WrongPassword123!',
            'password' => 'NewPassword456!',
            'password_confirmation' => 'NewPassword456!',
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertArrayHasKey('current_password', $payload['errors'] ?? []);

        $user->refresh();
        $this->assertTrue(Hash::check($oldPassword, $user->password));
    }

    private function makeUser(string $email, string $password): User
    {
        return User::create([
            'name' => 'Password Test',
            'email' => $email,
            'password' => $password,
        ]);
    }

    private function callPasswordController(User $user, array $payload)
    {
        $request = Request::create('/api/profile/password', 'PATCH', $payload);
        $request->setUserResolver(static fn () => $user);

        return app(ProfileController::class)->updatePassword($request);
    }
}
