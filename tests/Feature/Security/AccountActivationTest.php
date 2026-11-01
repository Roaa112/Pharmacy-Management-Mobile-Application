<?php

namespace Tests\Feature\Security;

use App\Mail\SendOtp;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AccountActivationTest extends TestCase
{
    use RefreshDatabase;

    private function register(string $email = 'new@example.com')
    {
        return $this->postJson('/api/user/register', [
            'name' => 'New', 'email' => $email, 'phone' => '0100'.random_int(1000000, 9999999),
            'password' => 'password123', 'confirm_password' => 'password123',
        ]);
    }

    public function test_registration_emails_a_code_that_verifies_the_account(): void
    {
        Mail::fake();
        $code = null;

        $this->register()->assertOk();
        // SendOtp is a queued mailable.
        Mail::assertQueued(SendOtp::class, function (SendOtp $mail) use (&$code) {
            $code = (string) $mail->otp;

            return $mail->hasTo('new@example.com');
        });

        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertFalse($user->is_verified);
        $this->assertTrue($user->isActive());
        $this->assertNotSame($code, $user->otp_code, 'the code is stored hashed');

        $this->postJson('/api/user/verify', ['email' => 'new@example.com', 'otp_code' => $code])->assertOk();
        $this->assertTrue($user->fresh()->is_verified);
    }

    public function test_verification_can_be_resent_without_revealing_accounts(): void
    {
        Mail::fake();
        $this->register();

        $this->postJson('/api/user/verify/resend', ['email' => 'new@example.com'])->assertOk();
        $this->postJson('/api/user/verify/resend', ['email' => 'nobody@example.com'])->assertOk();

        Mail::assertQueued(SendOtp::class, 2);
    }

    /**
     * Login keeps its existing contract: HTTP 200 with the answer under "original" (the app reads
     * original.token), also for refusals. The new refusals follow it and add original.code.
     */
    public function test_unverified_users_can_still_log_in_unless_the_flag_is_on(): void
    {
        $user = User::factory()->create(['is_verified' => false, 'password' => 'password123']);

        $this->postJson('/api/user/login', ['email' => $user->email, 'password' => 'password123'])->assertOk()->assertJsonStructure(['original' => ['token']]);

        config(['pharmacy.require_verified_login' => true]);
        $this->postJson('/api/user/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertJsonPath('original.code', 'email_not_verified')->assertJsonMissingPath('original.token');
    }

    public function test_deactivated_customer_cannot_log_in_and_their_tokens_stop_working(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        $token = $user->createToken('app')->plainTextToken;
        $this->withToken($token)->getJson('/api/user/user/data')->assertOk();

        $admin = Admin::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret-password']);
        $this->actingAs($admin, 'admin')->patch(route('dashboard.customers.toggleActive', $user))->assertRedirect();
        $this->assertFalse($user->fresh()->isActive());
        $this->assertSame(0, $user->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/user/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertJsonPath('original.code', 'account_inactive')->assertJsonMissingPath('original.token');

        // A token that slipped through (created before deactivation and not revoked) is refused too.
        $user->forceFill(['is_active' => true])->save();
        $late = $user->createToken('app')->plainTextToken;
        $user->forceFill(['is_active' => false])->save();
        $this->app['auth']->forgetGuards();
        $this->withToken($late)->getJson('/api/user/user/data')->assertStatus(403)->assertJsonPath('code', 'account_inactive');
    }
}
