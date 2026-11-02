<?php

namespace App\Modules\User;

use App\Models\User;
use App\Mail\SendOtp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Modules\User\Services\UserService;
use App\Modules\User\Requests\LoginRequest;
use App\Modules\User\Resources\UserResource;
use App\Modules\User\Requests\ListUsersRequest;
use App\Modules\User\Requests\VerifyOtpRequest;
use App\Modules\Shared\Enums\HttpStatusCodeEnum;
use App\Modules\User\Requests\CreateUserRequest;
use App\Modules\User\Requests\UpdateUserRequest;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\RateLimiter;


class UserController extends Controller
{
    // Security limits: code, not dashboard settings.
    private const MAX_OTP_ATTEMPTS = 5;
    private const OTP_TTL_MINUTES = 10;
    // New codes per account per hour, whatever the IP: each code allows MAX_OTP_ATTEMPTS guesses.
    private const MAX_OTP_SENDS_PER_HOUR = 5;

    public function __construct(private UserService $userService)
    {
    }



    // public function sendResetOtp(Request $request)
    // {
    //     $request->validate([
    //         'email' => 'required|email|exists:users,email',
    //     ]);

    //     $user = User::where('email', $request->email)->first();

    //     $otp = rand(100000, 999999);
    //     $user->otp_code = $otp;
    //     $user->otp_expires_at = now()->addMinutes(10);
    //     $user->save();

    //     Mail::to($user->email)->send(new SendOtp($otp));

    //     return response()->json(['message' => 'OTP sent to your email.']);
    // }
    // public function verifyOtp(Request $request)
    // {
    //     $request->validate([
    //         'email' => 'required|email',
    //         'otp_code' => 'required|digits:6',
    //     ]);

    //     $user = User::where('email', $request->email)->first();

    //     if (!$user || $user->otp_code !== $request->otp_code || now()->gt($user->otp_expires_at)) {
    //         return response()->json(['message' => 'Invalid or expired OTP.'], 422);
    //     }

    //     return response()->json(['message' => 'OTP verified. You may now reset your password.']);
    // }
    public function sendResetOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        // Same response whether or not the email exists, so accounts cannot be enumerated.
        if ($user) {
            $this->sendOtp($user);
        }

        return response()->json(['message' => 'If this email is registered, an OTP has been sent to it.']);
    }

    /** Send a new verification code to an unverified account (same answer whatever the email). */
    public function resendVerification(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->where('is_verified', false)->first();
        if ($user) {
            $this->sendOtp($user);
        }

        return response()->json(['message' => 'If this account needs verifying, a code has been sent to it.']);
    }

    /** A fresh 6-digit code, stored hashed, valid OTP_TTL_MINUTES; at most MAX_OTP_SENDS_PER_HOUR per account. */
    private function sendOtp(User $user): void
    {
        // Silently capped (the response never says whether a code was sent).
        if (RateLimiter::tooManyAttempts('otp-send:'.$user->id, self::MAX_OTP_SENDS_PER_HOUR)) {
            return;
        }
        RateLimiter::hit('otp-send:'.$user->id, 3600);

        $otp = (string) random_int(100000, 999999);
        $user->forceFill(['otp_code' => Hash::make($otp), 'otp_expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES), 'otp_attempts' => 0])->save();

        try {
            Mail::to($user->email)->send(new SendOtp($otp));
        } catch (\Throwable $e) {
            // The account exists either way; the user can ask for a new code.
            report($e);
        }
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp_code' => 'required|digits:6',
        ]);

        if (!$this->findUserWithValidOtp($request->email, $request->otp_code)) {
            return response()->json(['message' => 'Invalid or expired OTP.'], 422);
        }

        return response()->json(['message' => 'OTP verified. You may now reset your password.']);
    }

    public function resetPassword(Request $request)
    {
        // current_password is always verified when sent. It becomes mandatory once the
        // mobile app sends it (PASSWORD_CHANGE_REQUIRES_CURRENT=true).
        $mustConfirm = config('auth.password_change_requires_current') && $request->user()->password;

        $request->validate([
            'email' => 'nullable|email',
            'current_password' => [$mustConfirm ? 'required' : 'nullable', 'string', 'current_password:sanctum'],
            'password' => 'required|string|confirmed|min:8',
        ]);

        $user = $request->user();

        $user->password = Hash::make($request->password);
        $user->save();

        // Sign out every other device; keep the session that made this change.
        $currentToken = $user->currentAccessToken();
        $user->tokens()
            ->when($currentToken instanceof PersonalAccessToken, fn ($q) => $q->where('id', '!=', $currentToken->id))
            ->delete();

        return response()->json(['message' => 'Password reset successful.']);
    }

    /**
     * Unauthenticated password reset. Requires the OTP emailed by sendResetOtp().
     */
    public function resetPasswordAuth(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp_code' => 'required|digits:6',
            'password' => 'required|string|confirmed|min:8',
        ]);

        $user = $this->findUserWithValidOtp($request->email, $request->otp_code);

        if (!$user) {
            return response()->json(['message' => 'Invalid or expired OTP.'], 422);
        }

        $user->password = Hash::make($request->password);
        $user->otp_code = null;
        $user->otp_expires_at = null;
        $user->save();

        // A password reset must invalidate every existing session/token.
        $user->tokens()->delete();

        return response()->json(['message' => 'Password reset successful.']);
    }

    private function findUserWithValidOtp(string $email, string $otp): ?User
    {
        $user = User::where('email', $email)->first();

        if (!$user || !$user->otp_code || !$user->otp_expires_at || now()->gt($user->otp_expires_at)) {
            return null;
        }

        // The attempt is counted BEFORE the code is compared, in one atomic conditional UPDATE: of any
        // number of parallel requests, at most MAX_OTP_ATTEMPTS ever get to compare a guess, whatever
        // the cache driver or the number of IPs used. After that the code is destroyed.
        $counted = User::whereKey($user->id)->whereNotNull('otp_code')
            ->where('otp_attempts', '<', self::MAX_OTP_ATTEMPTS)->increment('otp_attempts');

        if ($counted !== 1) {
            $user->forceFill(['otp_code' => null, 'otp_expires_at' => null])->save();

            return null;
        }

        if (!Hash::check($otp, $user->otp_code)) {
            if ($user->fresh()->otp_attempts >= self::MAX_OTP_ATTEMPTS) {
                $user->forceFill(['otp_code' => null, 'otp_expires_at' => null])->save();
            }

            return null;
        }

        // A correct code clears the count (as the cache counter did before), so the app's
        // verify-otp -> reset/auth flow works whatever typos came first. Guessing is still capped:
        // only a caller who already holds the right code gets here.
        User::whereKey($user->id)->update(['otp_attempts' => 0]);

        return $user->fresh();
    }



    public function logout(Request $request)
    {
        return $this->userService->logout($request);
    }
    public function createUser(CreateUserRequest $request)
    {
        // التحقق من تأكيد كلمة المور
    if (!isset($request['confirm_password']) || $request['password'] !== $request['confirm_password']) {
        return response()->json(['error' => 'Password confirmation does not match'], 422);
    }

        $user = $this->userService->createUser($request->validated());

        // The verification code the /user/verify endpoint expects (it was never sent before).
        $this->sendOtp($user);


        return successJsonResponse(new UserResource($user), __('user.success.create_user'));
    }

    public function verify(VerifyOtpRequest $request)
    {
        $user = $this->findUserWithValidOtp($request->email, $request->otp_code);

        if (! $user) {
            return response()->json(['message' => 'Invalid or expired OTP'], 422);
        }

        $user->forceFill(['is_verified' => true, 'otp_code' => null, 'otp_expires_at' => null])->save();

        return response()->json([
            'message' => 'Account verified successfully!',
        ]);
    }

    public function login(LoginRequest $request){
        return response()->json(
            $this->userService->login($request->validated())
        );
    }




    public function updateUser($id, UpdateUserRequest $request)
    {
        // Users may only update their own profile.
        if ((int) $id !== $request->user()->id) {
            return errorJsonResponse('Forbidden', HttpStatusCodeEnum::Forbidden->value);
        }

        $current = $request->user();
        $emailChanged = strcasecmp((string) $request->validated('email'), (string) $current->email) !== 0;

        $user = $this->userService->updateUser($id, $request->validated());

        if ($emailChanged) {
            // A new address must be proven before it can receive password-reset codes on its own
            // authority: the account is unverified again and a code goes to the new address. Other
            // sessions are signed out (as for a password change), so a stolen token cannot quietly
            // move the account to another mailbox while the owner stays signed in elsewhere.
            $user = User::findOrFail($id);
            $user->forceFill(['is_verified' => false])->save();
            $currentToken = $current->currentAccessToken();
            $user->tokens()->when($currentToken instanceof PersonalAccessToken, fn ($q) => $q->where('id', '!=', $currentToken->id))->delete();
            $this->sendOtp($user);
        }

        return successJsonResponse(new UserResource($user), __('user.success.update_user'));
    }


    public function deleteUser($id)
    {
        $user = $this->userService->deleteUser($id);
        if ($user == true) {
            return successJsonResponse([], __('user.success.delete_user user_id = ' . $user['id']));
        } else {
            return errorJsonResponse("There is No user with id = " . $id, HttpStatusCodeEnum::Not_Found->value);
        }
    }

    public function toggleUserStatus($id)
    {
        $user = $this->userService->toggleUserStatus($id);
        return successJsonResponse(new UserResource($user), __('User.success.User_Status_Changed'));
    }

    public function listAllUsers(ListUsersRequest $request)
    {
        $users = $this->userService->listAllUsers($request->validated());
        return successJsonResponse(data_get($users, 'data'), __('users.success.get_all_Users'), data_get($users, 'count'));
    }

    public function getUserById($userId)
    {
        $user = $this->userService->getUserById($userId);
        if (!$user) {
            return errorJsonResponse("User $userId is not found!", HttpStatusCodeEnum::Not_Found->value);
        }
        return successJsonResponse(new UserResource($user), __('User.success.user_details'));
    }


}
