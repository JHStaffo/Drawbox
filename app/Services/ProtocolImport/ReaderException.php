<?php

namespace App\Services\ProtocolImport;

use RuntimeException;

class ReaderException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retry = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
