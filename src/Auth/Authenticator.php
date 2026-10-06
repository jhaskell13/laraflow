<?php

namespace Laraflow\Auth;

interface Authenticator
{
    public function authenticate(array $requestOptions = []): array;
}