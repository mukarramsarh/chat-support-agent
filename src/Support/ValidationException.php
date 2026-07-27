<?php

declare(strict_types=1);

namespace SupportAI\Support;

use RuntimeException;

/**
 * Thrown when strict input validation rejects a value. The message is safe to
 * show the client (it names the field and the rule, never internals), so
 * controllers can surface getMessage() directly in a 422.
 */
final class ValidationException extends RuntimeException
{
}
