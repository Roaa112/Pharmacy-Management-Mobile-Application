<?php

namespace App\Modules\Payments\Gateways;

use RuntimeException;

/** The provider could not be reached or refused the request. The message never contains secrets. */
class PaymentGatewayException extends RuntimeException
{
}
