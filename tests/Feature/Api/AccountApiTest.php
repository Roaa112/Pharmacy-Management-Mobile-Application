<?php

namespace Tests\Feature\Api;

use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountApiTest extends TestCase
{
    use RefreshDatabase;

    private function register(array $overrides = [])
    {
        return $this->postJson('/api/user/register', array_merge([
            'name' => 'Sara',
            'email' => 'sara@example.com',
            'phone' => '07700000001',
            'password' => 'secret-pass',
            'confirm_password' => 'secret-pass',
        ], $overrides));
    }

    public function test_registration_creates_an_unverified_user_with_a_hashed_password(): void
    {
        $this->register()
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.email', 'sara@example.com')
            ->assertJsonPath('data.is_verified', false)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.otp_code');

        $user = User::where('email', 'sara@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('secret-pass', $user->password));
    }

    public function test_registration_validates_input(): void
    {
        $this->register(['confirm_password' => 'other-pass'])->assertStatus(422);
        $this->register(['password' => 'short', 'confirm_password' => 'short'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->register(['email' => 'not-an-email'])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->register()->assertOk();
        $this->register(['phone' => '07700000002'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->register(['email' => 'other@example.com'])->assertStatus(422)->assertJsonValidationErrors('phone');

        $this->assertSame(1, User::count());
    }

    public function test_registration_ignores_attempts_to_self_verify(): void
    {
        $this->register(['isVerified' => true, 'is_verified' => true])->assertOk();

        $this->assertFalse(User::where('email', 'sara@example.com')->value('is_verified'));
    }

    public function test_login_keeps_the_response_shape_the_app_parses(): void
    {
        // The app reads original.token: keep this shape until the app is updated (see audit notes).
        $user = User::factory()->create(['email' => 'sara@example.com']);

        $this->postJson('/api/user/login', ['email' => 'sara@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonStructure(['original' => ['token', 'user' => ['id', 'email']]])
            ->assertJsonMissingPath('original.user.password')
            ->assertJsonMissingPath('original.user.otp_code');

        $this->postJson('/api/user/login', ['email' => 'sara@example.com', 'password' => 'wrong'])
            ->assertJsonPath('original.message', 'Unauthorized')
            ->assertJsonMissingPath('original.token');

        $this->postJson('/api/user/login', ['email' => 'nobody@example.com', 'password' => 'password'])
            ->assertJsonMissingPath('original.token');

        $this->postJson('/api/user/login', [])->assertStatus(422);
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_verify_endpoint_verifies_with_a_valid_otp(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'sara@example.com']);
        $user->forceFill(['otp_code' => Hash::make('123456'), 'otp_expires_at' => now()->addMinutes(10)])->save();

        $this->postJson('/api/user/verify', ['email' => 'sara@example.com', 'otp_code' => '000000'])->assertStatus(422);
        $this->assertFalse($user->fresh()->is_verified);

        $this->postJson('/api/user/verify', ['email' => 'sara@example.com', 'otp_code' => '123456'])
            ->assertOk()
            ->assertJsonPath('message', 'Account verified successfully!');

        $user->refresh();
        $this->assertTrue($user->is_verified);
        $this->assertNull($user->otp_code);

        // The code is single-use.
        $this->postJson('/api/user/verify', ['email' => 'sara@example.com', 'otp_code' => '123456'])->assertStatus(422);
    }

    public function test_verify_rejects_unknown_emails_expired_codes_and_bad_input(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'sara@example.com']);
        $user->forceFill(['otp_code' => Hash::make('123456'), 'otp_expires_at' => now()->subMinute()])->save();

        $this->postJson('/api/user/verify', ['email' => 'sara@example.com', 'otp_code' => '123456'])->assertStatus(422);
        $this->postJson('/api/user/verify', ['email' => 'ghost@example.com', 'otp_code' => '123456'])->assertStatus(422);
        $this->postJson('/api/user/verify', ['email' => 'sara@example.com'])->assertStatus(422)->assertJsonValidationErrors('otp_code');

        $this->assertFalse($user->fresh()->is_verified);
    }

    public function test_user_data_and_token_check_return_the_caller_without_secrets(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('app')->plainTextToken;

        $this->withToken($token)->getJson('/api/user/user/data')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('token', $token)
            ->assertJsonMissingPath('data.password');

        $this->withToken($token)->getJson('/api/token/check')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('user.otp_code')
            ->assertJsonMissingPath('user.remember_token');
    }

    public function test_profile_update_changes_own_profile_and_validates_uniqueness(): void
    {
        $user = User::factory()->create(['phone' => '07700000001']);
        $other = User::factory()->create(['email' => 'taken@example.com', 'phone' => '07700000009']);
        Sanctum::actingAs($user);

        $this->putJson("/api/user/update/{$user->id}", ['name' => 'New', 'email' => $user->email, 'phone' => '07700000001'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New');

        $this->putJson("/api/user/update/{$user->id}", ['name' => 'New', 'email' => 'taken@example.com', 'phone' => '07700000001'])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $this->putJson("/api/user/update/{$other->id}", ['name' => 'Hacked', 'email' => 'x@example.com', 'phone' => '1'])
            ->assertForbidden();
        $this->assertSame($other->name, $other->fresh()->name);
    }

    public function test_logout_revokes_tokens(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('app')->plainTextToken;

        $this->withToken($token)->postJson('/api/logout')->assertOk();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_contact_message_is_stored_for_the_caller(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/user/contact-messages', [
            'name' => 'Sara',
            'email' => 'sara@example.com',
            'message' => 'Do you stock vitamin D?',
            'user_id' => $other->id,
        ])->assertCreated()->assertJsonPath('success', true);

        $this->assertDatabaseHas('contact_messages', ['user_id' => $user->id, 'message' => 'Do you stock vitamin D?']);
        $this->assertSame(0, ContactMessage::where('user_id', $other->id)->count());
    }

    public function test_contact_message_is_validated(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/user/contact-messages', ['name' => 'Sara'])->assertStatus(422)->assertJsonValidationErrors('message');
        $this->postJson('/api/user/contact-messages', ['name' => 'Sara', 'message' => 'x', 'email' => 'nope'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson('/api/user/contact-messages', ['name' => 'Sara', 'message' => str_repeat('a', 5001)])
            ->assertStatus(422)->assertJsonValidationErrors('message');

        $this->assertSame(0, ContactMessage::count());
    }
}
