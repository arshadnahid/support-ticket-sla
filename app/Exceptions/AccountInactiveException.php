<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when a deactivated user logs in or uses a token. Rendered by ApiExceptionRenderer (403).
 */
class AccountInactiveException extends Exception {}
