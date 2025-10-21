<?php

namespace App\Modules\User\Services;

use App\Modules\User\Resources\UserCollection;
use App\Modules\User\Repositories\UsersRepository;
use App\Modules\User\Requests\ListAllUsersRequest;

class UserService
{
    public function __construct(private UsersRepository $usersRepository)
    {
    }
    public function logout( $request)
    {
        $user = $this->usersRepository->getAuthenticatedUser();

        if (!$user) {
            Log::error('User is not authenticated');

            return response()->json([
                'status' => 401,
                'message' => 'Unauthenticated',
                'data' => null
            ], 401);
        }


        $user->tokens()->delete();

        return response()->json([
            'status' => 200,
            'message' => 'Logged out successfully',
            'data' => null
        ]);
    }





    public function createUser($request)
    {
        $user = $this->constructUserModel($request);
        return $this->usersRepository->create($user);
    }
    // public function verifyOtp($email, $otp)
    // {

    //     return $this->usersRepository->verifyUserOtp($email, $otp);
    // }

    public function login(array $data)
    {
        $user = $this->usersRepository->login($data);

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        $token = $user->createToken($user->email)->plainTextToken;


        return response()->json([
            'token' => $token,
            'user'=>$user,
        ]);
    }

    
    public function updateUser($id, $request)
    {
        $user = $this->constructUserModel($request);
        return $this->usersRepository->update($id, $user);
    }

    public function deleteUser($id)
    {
        return $this->usersRepository->delete($id);
    }

    public function listAllUsers(array $queryParameters)
    {
        // Construct Query Criteria
        $listAllUsers = (new ListAllUsersRequest)->constructQueryCriteria($queryParameters);

        // Get Countries from Database
        $users = $this->usersRepository->findAllBy($listAllUsers);

        return [
            'data' => new UserCollection($users['data']),
            'count' => $users['count']
        ];
    }

    public function toggleUserStatus($id)
    {
        $user = $this->usersRepository->find($id);
        $user->toggleStatus();
        return $user;
    }

    public function getUserById($id)
    {
        return $this->usersRepository->find($id);
    }

    public function constructUserModel($request)
    {
        $userModel = [
            'name' => $request['name'],

            'email' => $request['email'],
            'phone' => $request['phone'] ?? null,
            'social_id' => $request['social_id'] ?? null,
            'social_provider' => $request['social_provider'] ?? null,
            'is_verified' => $request['isVerified'] ?? false,
            'otp_code' => $request['otp_code'] ?? null,
            'otp_expires_at' => $request['otp_expires_at'] ?? null,
        ];

        if (isset($request['password'])) {
            $userModel['password'] = $request['password'];
        }

        return $userModel;
    }

    public function getUserByEmail($email){
        return $this->usersRepository->getUserByEmail($email);
    }

    public function changePassword($request){
        $user = $this->usersRepository->find($request['id']);
        $user->password = bcrypt($request['password']);
        return $user->save();
    }
}
