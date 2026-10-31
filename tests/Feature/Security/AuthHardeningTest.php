<?php

namespace Tests\Feature\Security;

use App\Mail\SendOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_otp_is_destroyed_after_five_wrong_codes_even_from_different_ips(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $this->postJson('/api/user/password/forgot', ['email' => $user->email])->assertOk();

        $otp = null;
        Mail::assertQueued(SendOtp::class, function ($mail) use (&$otp) {
            $otp = $mail->otp;
            return true;
        });
        $wrong = $otp === '111111' ? '222222' : '111111';

        // Each guess from a different IP, so the per-IP throttle never triggers.
        for ($i = 1; $i <= 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
                ->postJson('/api/user/password/verify-otp', ['email' => $user->email, 'otp_code' => $wrong])
                ->assertStatus(422);
        }

        // The correct code no longer works: the attacker's guesses burned it.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
            ->postJson('/api/password/reset/auth', [
                'email' => $user->email, 'otp_code' => $otp,
                'password' => 'new-password-123', 'password_confirmation' => 'new-password-123',
            ])->assertStatus(422);

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        $this->assertNull($user->fresh()->otp_code);
    }

    public function test_wrong_current_password_is_rejected_when_sent(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/user/password/reset', [
            'current_password' => 'not-my-password',
            'password' => 'new-password-123', 'password_confirmation' => 'new-password-123',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_current_password_is_optional_until_the_flag_is_enabled(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $payload = ['password' => 'new-password-123', 'password_confirmation' => 'new-password-123'];

        $this->postJson('/api/user/password/reset', $payload)->assertOk();

        config(['auth.password_change_requires_current' => true]);
        $this->postJson('/api/user/password/reset', $payload)->assertStatus(422);
        $this->postJson('/api/user/password/reset', $payload + ['current_password' => 'new-password-123'])->assertOk();
    }

    public function test_broken_web_password_reset_routes_are_gone_and_login_page_still_renders(): void
    {
        $this->get('/password/reset')->assertNotFound();
        $this->post('/password/email', ['email' => 'x@example.com'])->assertNotFound();
        $this->get('/login')->assertOk();
    }
}
