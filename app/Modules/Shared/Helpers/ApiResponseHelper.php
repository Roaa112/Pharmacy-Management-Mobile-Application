<?php

use App\Modules\Shared\Enums\HttpStatusCodeEnum;


function successJsonResponse($data, $message= null, $count = null, $statusCode = HttpStatusCodeEnum::Success->value)
{
    $response = [
        'status' => 'success',
        'message' => $message,
        'data' => $data,
    ];
    if($count){
        $response['count'] = $count;
    }

    return response()->json($response, $statusCode);
}

function errorJsonResponse($message = null, $statusCode = HttpStatusCodeEnum::Bad_Request->value, $errors = [])
{
    $response = [
        'status' => 'error',
        'message' => $message,
        'data' => null,
    ];
    if (count($errors)){
        $response['errors'] = $errors;
    }

    return response()->json($response, $statusCode);
}