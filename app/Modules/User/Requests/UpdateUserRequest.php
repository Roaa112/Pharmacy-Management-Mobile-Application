<?php

namespace App\Modules\User\Requests;

use Illuminate\Validation\Rules\Enum;
use App\Modules\User\Enums\UserTypeEnum;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'userId' => 'required|integer|min:1|exists:users,id',
            'name' => 'required|string|max:255',
            'email' => "required|email|unique:users,email,$this->userId",
            'phone' => "required|string|unique:users,phone,$this->userId",
            'isActive' => 'nullable|boolean',
            'isVerified' => 'nullable|boolean',
        ];
    }

    public function prepareForValidation()
    {
        $this->merge([
            'userId' => (int) $this->route('userId'),
        ]);
    }

    public function messages(): array
    {
        return [
            'userId.required' => 'User ID is required.',
            'userId.integer' => 'User ID must be an integer.',
            'userId.min' => 'User ID must be at least 1.',
            'userId.exists' => 'The specified user does not exist.',

            'name.required' => 'The name field is required.',
            'name.string' => 'The name must be a string.',
            'name.max' => 'The name may not be greater than 255 characters.',

            'email.required' => 'The email field is required.',
            'email.email' => 'The email must be a valid email address.',
            'email.unique' => 'The email has already been taken by another user.',

            'phone.required' => 'The phone number is required.',
            'phone.string' => 'The phone number must be a string.',
            'phone.unique' => 'The phone number has already been taken by another user.',

            'isActive.boolean' => 'The active status must be true or false.',
            'isVerified.boolean' => 'The verified status must be true or false.',
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        $response = response()->json([
            'status' => false,
            'message' => 'Validation error',
            'errors' => $validator->errors(),
        ], 422);

        throw new \Illuminate\Validation\ValidationException($validator, $response);
    }
}
