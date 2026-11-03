<?php

namespace Tests\Feature\Security;

use App\Mail\PasswordChanged;
use App\Mail\SendOtp;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** The logged-in password change: a token alone must not be enough to take an account over silently. */
class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private const NEW = ['password' => 'new-password-123', 'password_confirmation' => 'new-password-123'];

    private function withTokens(User $user): array
    {
        return [$user->createToken('phone')->plainTextToken, $user->createToken('tablet')->plainTextToken];
    }

    public function test_in_secure_mode_a_stolen_token_cannot_change_the_password(): void
    {
        config(['auth.password_change_requires_current' => true]);
        Mail::fake();
        $owner = User::factory()->create();
        [$stolen, $ownersOther] = $this->withTokens($owner);

        $this->withToken($stolen)->postJson('/api/user/password/reset', self::NEW)->assertStatus(422);
        $this->withToken($stolen)->postJson('/api/user/password/reset', self::NEW + ['current_password' => 'guess-1234'])->assertStatus(422);
        $this->withToken($stolen)->postJson('/api/user/password/reset', self::NEW + ['otp_code' => '000000'])->assertStatus(422);

        $this->assertTrue(Hash::check('password', $owner->fresh()->password), 'password unchanged');
        $this->assertSame(2, $owner->tokens()->count(), 'the owner is not signed out');
        Mail::assertNothingQueued();
    }

    public function test_in_secure_mode_the_current_password_or_an_emailed_code_proves_the_owner(): void
    {
        config(['auth.password_change_requires_current' => true]);
        $owner = User::factory()->create();
        [$token, $other] = $this->withTokens($owner);

        $this->withToken($token)->postJson('/api/user/password/reset', self::NEW + ['current_password' => 'password'])->assertOk();
        $this->assertTrue(Hash::check('new-password-123', $owner->fresh()->password));
        $this->assertSame(1, $owner->tokens()->count(), 'other devices are signed out');

        // Forgotten password while signed in: the code from password/forgot, used once.
        $owner->forceFill(['otp_code' => Hash::make('424242'), 'otp_expires_at' => now()->addMinutes(10), 'otp_attempts' => 0])->save();
        $change = ['password' => 'third-password-1', 'password_confirmation' => 'third-password-1', 'otp_code' => '424242'];
        $this->withToken($token)->postJson('/api/user/password/reset', $change)->assertOk();
        $this->assertTrue(Hash::check('third-password-1', $owner->fresh()->password));
        $this->assertNull($owner->fresh()->otp_code);
        $this->withToken($token)->postJson('/api/user/password/reset', $change)->assertStatus(422);
    }

    public function test_in_compatibility_mode_a_change_without_proof_is_never_silent(): void
    {
        config(['auth.password_change_requires_current' => false]);
        Mail::fake();
        $owner = User::factory()->create();
        [$token] = $this->withTokens($owner);

        $this->withToken($token)->postJson('/api/user/password/reset', self::NEW)->assertOk();

        Mail::assertQueued(PasswordChanged::class, fn ($mail) => $mail->hasTo($owner->email));

        // A change proved with the current password needs no alarm.
        Mail::fake();
        $this->withToken($token)->postJson('/api/user/password/reset', ['password' => 'x-password-22', 'password_confirmation' => 'x-password-22', 'current_password' => 'new-password-123'])->assertOk();
        Mail::assertNothingQueued();
    }

    public function test_codes_and_notices_are_encrypted_on_the_queue(): void
    {
        $this->assertInstanceOf(ShouldBeEncrypted::class, new SendOtp('123456'));
        $this->assertInstanceOf(ShouldBeEncrypted::class, new PasswordChanged('Name'));
    }
}
