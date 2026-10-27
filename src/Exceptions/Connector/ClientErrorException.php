<?php

declare(strict_types=1);

namespace Laraflow\Exceptions\Connector;

/**
 * The request was rejected for a reason other than authentication, absence, or rate limiting: any other 4xx.
 */
class ClientErrorException extends RequestFailedException {}
