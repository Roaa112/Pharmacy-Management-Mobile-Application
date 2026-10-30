<?php

namespace Tests\Feature\Security;

use App\Mail\SendOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function requestOtp(User $user): string
    {
        $otp = null;
        Mail::fake();

        $this->postJson('/api/user/password/forgot', ['email' => $user->email])->assertOk();

        Mail::assertQueued(SendOtp::class, function (SendOtp $mail) use (&$otp) {
            $otp = $mail->otp;
            return true;
        });

        return $otp;
    }

    public function test_password_cannot_be_reset_with_only_an_email(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/password/reset/auth', [
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_password_cannot_be_reset_with_a_wrong_otp(): void
    {
        $user = User::factory()->create();
        $otp = $this->requestOtp($user);
        $wrong = $otp === '123456' ? '654321' : '123456';

        $this->postJson('/api/password/reset/auth', [
            'email' => $user->email,
            'otp_code' => $wrong,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_password_reset_with_valid_otp_changes_password_and_revokes_tokens(): void
    {
        $user = User::factory()->create();
        $user->createToken('phone');
        $otp = $this->requestOtp($user);

        $this->assertNotSame($otp, $user->fresh()->otp_code, 'OTP must not be stored in plain text');

        $this->postJson('/api/password/reset/auth', [
            'email' => $user->email,
            'otp_code' => $otp,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('new-password-123', $user->password));
        $this->assertSame(0, $user->tokens()->count());

        // The OTP is single-use.
        $this->postJson('/api/password/reset/auth', [
            'email' => $user->email,
            'otp_code' => $otp,
            'password' => 'another-password-1',
            'password_confirmation' => 'another-password-1',
        ])->assertStatus(422);
    }

    public function test_expired_otp_is_rejected(): void
    {
        $user = User::factory()->create();
        $otp = $this->requestOtp($user);

        $this->travel(11)->minutes();

        $this->postJson('/api/user/password/verify-otp', ['email' => $user->email, 'otp_code' => $otp])
            ->assertStatus(422);
    }

    public function test_forgot_password_does_not_reveal_whether_an_email_exists(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $known = $this->postJson('/api/user/password/forgot', ['email' => $user->email]);
        $unknown = $this->postJson('/api/user/password/forgot', ['email' => 'nobody@example.com']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->json(), $unknown->json());
        Mail::assertQueued(SendOtp::class, 1);
    }

    public function test_login_is_rate_limited(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/user/login', ['email' => $user->email, 'password' => 'wrong']);
        }

        $this->postJson('/api/user/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(429);
    }

    public function test_token_is_rejected_after_logout(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('phone')->plainTextToken;

        $this->withToken($token)->postJson('/api/logout')->assertOk();
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/token/check')->assertUnauthorized();
    }

    public function test_tokens_expire(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('phone')->plainTextToken;

        $this->travel(31)->days();

        $this->withToken($token)->getJson('/api/token/check')->assertUnauthorized();
    }

    public function test_user_cannot_update_another_users_profile(): void
    {
        $attacker = User::factory()->create();
        $victim = User::factory()->create();
        Sanctum::actingAs($attacker);

        $this->putJson("/api/user/update/{$victim->id}", [
            'name' => 'Hacked',
            'email' => 'attacker@example.com',
            'phone' => '0999',
        ])->assertForbidden();

        $this->assertSame($victim->email, $victim->fresh()->email);
    }

    public function test_user_cannot_mark_themselves_verified_through_profile_update(): void
    {
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        $this->putJson("/api/user/update/{$user->id}", [
            'name' => 'New Name',
            'email' => $user->email,
            'phone' => $user->phone,
            'isVerified' => true,
        ])->assertOk();

        $this->assertFalse($user->fresh()->is_verified);
        $this->assertSame('New Name', $user->fresh()->name);
    }

    public function test_user_data_endpoint_hides_secrets_and_does_not_mint_tokens(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('phone')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/user/user/data')->assertOk();

        $this->assertArrayNotHasKey('password', $response->json('data'));
        $this->assertArrayNotHasKey('otp_code', $response->json('data'));
        $this->assertSame($token, $response->json('token'));
        $this->assertSame(1, $user->tokens()->count());
    }
}
