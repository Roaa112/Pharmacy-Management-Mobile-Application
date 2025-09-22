<?php

namespace App\Modules\Shared\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;


class BaseGetRequestValidator extends FormRequest
{
     /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        return [
            "limit" => 'required_with:offset|integer|min:1|max:100',
            "offset" => 'required_with:limit|integer|min:0',
            "sort" => 'required_with:sortBy|in:ASC,DESC,asc,desc',
            "sortBy" => 'required_with:sort|string',
        ];
    }

      /**
     * Handle a failed validation attempt.
     *
     * @param \Illuminate\Contracts\Validation\Validator $validator
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(errorJsonResponse('Invalid data sent', 422, $validator->errors()->messages()));
    }

    public function validated($key = null, $default = null)
    {
        $validatedFields = parent::validated();
        $newStructure = [
            "limit" => (int)data_get($validatedFields, 'limit'),
            "offset" => (int)data_get($validatedFields, 'offset'),
            "sort" => data_get($validatedFields, 'sort'),
            "sortBy" => data_get($validatedFields, 'sortBy'),
            "filters" => excludeKeysFromArray($validatedFields, ['limit', 'offset', 'sort', 'sortBy'])
        ];

        return array_filter($newStructure, function ($value) {
            return $value !== null;
        });
    }
}
