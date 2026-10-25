<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Connector;

/**
 * The provider refused the credentials: 401 or 403.
 */
class AuthenticationFailedException extends RequestFailedException {}
