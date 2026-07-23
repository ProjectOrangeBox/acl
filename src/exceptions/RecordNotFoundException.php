<?php

declare(strict_types=1);

namespace orange\acl\exceptions;

use Throwable;

class RecordNotFoundException extends AclException
{
    /**
     * A missing record maps naturally onto 404 - the default here so every
     * throw site carries it without repeating the code.
     */
    public function __construct(string $message = '', int $code = 404, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
