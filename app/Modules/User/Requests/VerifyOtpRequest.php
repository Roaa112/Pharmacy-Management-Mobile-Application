<?php
namespace App\Modules\User\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
{
    public function rules(): array
    {
        return [
          
            'otp_code' => 'required|string',
        ];
    }
}
