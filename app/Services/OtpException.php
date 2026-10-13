<?php

namespace App\Services;

use RuntimeException;

/** OTP fail hone par: message + HTTP status code */
class OtpException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}