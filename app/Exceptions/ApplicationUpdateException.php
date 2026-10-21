<?php

namespace App\Exceptions;

use RuntimeException;

class ApplicationUpdateException extends RuntimeException
{
    // Domain exception whose messages are safe to show to an administrator.
}
