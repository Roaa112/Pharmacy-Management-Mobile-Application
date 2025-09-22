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


class UserController extends Controller
{
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
    public function resetPassword(Request $request)
    {

        // $otp = request()->header('otp');

        $request->validate([
            'email' => 'nullable|email',
            'password' => 'required|string|confirmed|min:8',
        ]);

        // $user = User::where('email', $request->email)->first();

    $user = auth()->user();
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }
        // if (!$user || $user->otp_code !== $otp|| now()->gt($user->otp_expires_at)) {
        //     return response()->json(['message' => 'Invalid or expired OTP.'], 422);
        // }

        $user->password = Hash::make($request->password);
        // $user->otp_code = null;
        // $user->otp_expires_at = null;
        $user->save();

        return response()->json(['message' => 'Password reset successful.']);
    }


  
   public function resetPasswordAuth(Request $request)
    {

        // $otp = request()->header('otp');

        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string|confirmed|min:8',
        ]);

         $user = User::where('email', $request->email)->first();

   
        if (!$user) {
            return response()->json(['message' => 'email not found.'], 404);
        }
        // if (!$user || $user->otp_code !== $otp|| now()->gt($user->otp_expires_at)) {
        //     return response()->json(['message' => 'Invalid or expired OTP.'], 422);
        // }

        $user->password = Hash::make($request->password);
        // $user->otp_code = null;
        // $user->otp_expires_at = null;
        $user->save();

        return response()->json(['message' => 'Password reset successful.']);
    }
  
  
  
    public function logout(Request $request)
    {
        return $this->userService->logout($request);
    }
    public function createUser(CreateUserRequest $request)
    {
        // التحقق من تأكيد كلمة المور
    if (!isset($request['confirm_password']) || $request['password'] !== $request['confirm_password']) {
        return ['error' => 'Password confirmation does not match'];
    }

        $user = $this->userService->createUser($request->validated());


        return successJsonResponse(new UserResource($user), __('user.success.create_user'));
    }

    // public function verify(VerifyOtpRequest $request)
    // {
    //     $user = $this->userService->verifyOtp($request->email, $request->otp_code);

    //     if (! $user) {
    //         return response()->json(['message' => 'Invalid or expired OTP'], 422);
    //     }

    //     return response()->json([
    //         'message' => 'Account verified successfully!',
    //     ]);
    // }

    public function login(LoginRequest $request){
        return response()->json(
            $this->userService->login($request->validated())
        );
    }




    public function updateUser($id, UpdateUserRequest $request)
    {
        $user = $this->userService->updateUser($id, $request->validated());
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
