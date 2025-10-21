<?php

namespace App\Modules\Shared\Enums;

enum HttpStatusCodeEnum: int
{
    case Success = 200;
    case Not_Found = 404;
    case Bad_Request = 400;
    case Unauthorized = 401;
    case Forbidden = 403;
    case Method_Not_Allowed = 405;
    case Request_Timeout = 408;
    case No_Response = 444;
    case Server_Error = 500;
    case Bad_Gateway = 502;

    public static function values(): array
    {
        return array_column(self::cases(),'value');
    }

    public function count() : int 
    {
        return count(self::cases());
    }
}