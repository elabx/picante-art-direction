<?php

namespace App\Engines;

use RuntimeException;

class KreaException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $detail = null,
    ) {
        parent::__construct($message);
    }
}
