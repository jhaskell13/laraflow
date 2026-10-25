<?php

declare(strict_types=1);

namespace Laraflow\Exceptions;

/**
 * reveal() was called on a Secret that was unserialized and so carries
 * no value.
 */
class SecretRedactedException extends LaraflowException {}
