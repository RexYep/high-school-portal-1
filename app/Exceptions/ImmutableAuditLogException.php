<?php

namespace App\Exceptions;

use LogicException;

class ImmutableAuditLogException extends LogicException
{
    public static function forOperation(string $operation): self
    {
        return new self("Audit log entries are append-only and cannot be {$operation}.");
    }
}
