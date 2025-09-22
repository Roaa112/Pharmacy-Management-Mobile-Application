<?php

namespace App\Modules\User;

use App\Models\User;
use App\Mail\SendOtp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
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


class ApiUserController extends Controller
{
    public function __construct(private UserService $userService)
    {
    }


   
    
    public function getUserData()
    {
        $user = Auth::guard('api')->user(); // لو انتي شغالة على API Guard, أو عادي بس Auth::user()
    
        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }
    
        return response()->json([
            'data' => new UserResource($user),
            'token' => $user->createToken('API Token')->plainTextToken, // لو حابة تبعتي توكن كمان
        ], 200);
    }

    
}
