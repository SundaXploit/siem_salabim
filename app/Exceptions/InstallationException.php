<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An installer failure whose message is deliberately safe to show to an
 * operator. Unexpected dependency/database exceptions must never be promoted
 * to this type because they may contain credentials, URLs, or SQL details.
 */
class InstallationException extends RuntimeException
{
    // Marker exception.
}
