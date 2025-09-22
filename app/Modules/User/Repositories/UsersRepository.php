<?php

namespace App\Modules\User\Repositories;

use App\Models\User;
use App\Modules\User\Requests\CreateUserRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use App\Mail\SendOtp;
use App\Modules\Shared\Repositories\BaseRepository;

class UsersRepository extends BaseRepository
{
    public function __construct(private User $model)
    {
        parent::__construct($model);
    }
    public function getAuthenticatedUser(): ?User
    {
        return auth('sanctum')->user();
    }
    public function create($user): User
    {
        // $otp = rand(100000, 999999);

        $user = User::create([
            'name' => $user['name'],
            'email' => $user['email'],
            'phone' => $user['phone'],
            'password' => Hash::make($user['password']),
            // 'otp_code' => $otp,
            'otp_expires_at' => Carbon::now()->addMinutes(10),
            'is_verified' => false,
            'is_active' => true,
        ]);

        // Mail::to($user->email)->send(new SendOtp($user->otp_code));

        return $user;
    }
  
//    public function verifyUserOtp($email, $otp)
//     {
//         $user = User::where('email', $email)
//             ->where('otp_code', $otp)
//             ->where('otp_expires_at', '>=', now())
//             ->first();

//         if (! $user) {
//             return false;
//         }

//         $user->update([
//             'is_verified' => true,
//             'otp_code' => null,
//             'otp_expires_at' => null,
//         ]);

//         return $user;
//     }
    public function login(array $credentials)
    {
        $user = User::where('email', $credentials['email'])->first();
    
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return null;
        }
    
        return $user;
    }
 
    public function getUserByEmail($email){
        $user = $this->model::where('email',$email)->first();
        return $user;
    }
}
