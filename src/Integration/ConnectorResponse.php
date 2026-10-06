<?php

namespace Laraflow\Integration;

class ConnectorResponse
{
    public function __construct(
        protected int $status,
        protected array $headers,
        protected mixed $body,
    ) {
    }

    public function status(): int
    {
        return $this->status;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): mixed
    {
        return $this->body;
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function failed(): bool
    {
        return ! $this->successful();
    }
}