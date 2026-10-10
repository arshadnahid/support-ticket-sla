<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown on a wrong email/password. Rendered by ApiExceptionRenderer (401).
 */
class InvalidCredentialsException extends Exception {}
