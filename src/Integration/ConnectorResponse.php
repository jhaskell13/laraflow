<?php

declare(strict_types=1);

namespace Laraflow\Integration;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Arr;
use JsonException;
use Laraflow\Exceptions\Connector\RequestFailedException;
use Laraflow\Exceptions\Connector\ResponseDecodingException;

/**
 * The result of a connector request, independent of transport.
 *
 * Keeps the body as received. Decoding is explicit and lazy: json()
 * decodes it and refuses malformed JSON with an exception; body() gives
 * the decoded value when the server declared JSON and the raw string
 * otherwise, so an HTML maintenance page and a JSON `null` can no longer
 * be mistaken for each other.
 *
 * Immutable. A connector attaches the error its mapper found with
 * withError(); the response then reports failed() and throw() raises it.
 */
final class ConnectorResponse
{
    /**
     * @var array<string, list<string>>
     */
    private array $headers;

    private mixed $decoded = null;

    private bool $hasDecoded = false;

    /**
     * @param  array<string, string|array<string>>  $headers  PSR shape (name => values) or name => value.
     * @param  string  $raw  The body exactly as received.
     */
    public function __construct(
        private readonly int $status,
        array $headers = [],
        private readonly string $raw = '',
        private readonly ?RequestFailedException $error = null,
        private readonly int $attempts = 1,
    ) {
        $normalised = [];

        foreach ($headers as $name => $values) {
            $normalised[$name] = array_map('strval', is_array($values) ? array_values($values) : [$values]);
        }

        $this->headers = $normalised;
    }

    /**
     * A response with a JSON body, for tests and replacement transports.
     *
     * @param  array<string, string|list<string>>  $headers
     */
    public static function fake(int $status = 200, mixed $json = null, array $headers = []): self
    {
        return new self(
            $status,
            $headers + ['Content-Type' => 'application/json'],
            $json === null ? '' : (string) json_encode($json, JSON_THROW_ON_ERROR),
        );
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, list<string>>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * The first value of a header, matched case-insensitively.
     */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $candidate => $values) {
            if (strcasecmp($candidate, $name) === 0) {
                return $values[0] ?? null;
            }
        }

        return null;
    }

    /**
     * The body exactly as received.
     */
    public function raw(): string
    {
        return $this->raw;
    }

    /**
     * Whether the server declared a JSON body.
     */
    public function isJson(): bool
    {
        $type = strtolower($this->header('Content-Type') ?? '');

        return str_contains($type, '/json') || str_contains($type, '+json');
    }

    /**
     * The body decoded as JSON, whatever the server declared; some send
     * JSON as text/plain. An empty body decodes to null.
     *
     * @param  string|null  $key  Dot-notation path into the decoded value.
     *
     * @throws ResponseDecodingException when the body is not valid JSON.
     */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        if (! $this->hasDecoded) {
            $this->decoded = $this->decode();
            $this->hasDecoded = true;
        }

        return $key === null ? $this->decoded : Arr::get($this->decoded, $key, $default);
    }

    /**
     * The decoded JSON when the server declared JSON (malformed JSON is
     * then an error — the server said one thing and sent another), the
     * raw string otherwise, null for an empty body.
     *
     * @throws ResponseDecodingException
     */
    public function body(): mixed
    {
        if ($this->isJson()) {
            return $this->json();
        }

        return $this->raw === '' ? null : $this->raw;
    }

    /**
     * 2xx and no error attached. A connector's mapper may mark a 200 as
     * failed when the provider reports errors in the body.
     */
    public function successful(): bool
    {
        return $this->error === null && $this->status >= 200 && $this->status < 300;
    }

    public function failed(): bool
    {
        return ! $this->successful();
    }

    /**
     * The exception this response represents, when a connector's mapper
     * found one. Null for a success — and for a response that never went
     * through a mapper.
     */
    public function error(): ?RequestFailedException
    {
        return $this->error;
    }

    public function withError(?RequestFailedException $error): self
    {
        return new self($this->status, $this->headers, $this->raw, $error, $this->attempts);
    }

    /**
     * How many wire attempts produced this response; 1 unless a
     * transport retry policy repeated the request.
     */
    public function attempts(): int
    {
        return $this->attempts;
    }

    public function withAttempts(int $attempts): self
    {
        return new self($this->status, $this->headers, $this->raw, $this->error, $attempts);
    }

    /**
     * The provider's requested wait from `Retry-After`, in seconds: a
     * delay-seconds value as is, an HTTP-date measured from $now (zero
     * once past). Null when absent or unusable.
     */
    public function retryAfterSeconds(DateTimeInterface $now): ?int
    {
        $value = $this->header('Retry-After');

        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        // RFC7231's format escapes the literal "GMT" rather than parsing it
        // as a zone, so without this an HTTP-date would be read in the
        // application's default timezone and the wait would be off by its
        // offset. RFC2822 carries its own offset and is unaffected.
        $gmt = new DateTimeZone('GMT');

        $until = DateTimeImmutable::createFromFormat(DateTimeInterface::RFC7231, $value, $gmt)
            ?: DateTimeImmutable::createFromFormat(DateTimeInterface::RFC2822, $value, $gmt);

        return $until === false ? null : max(0, $until->getTimestamp() - $now->getTimestamp());
    }

    /**
     * Raise the attached error, or a generic one for an unmapped error
     * status. Returns the response otherwise, for chaining.
     *
     * @throws RequestFailedException
     */
    public function throw(): self
    {
        if ($this->error !== null) {
            throw $this->error;
        }

        if (! $this->successful()) {
            throw RequestFailedException::forStatus($this);
        }

        return $this;
    }

    /**
     * A durable representation — status, headers, and body — suitable
     * as step output.
     *
     * @return array{status: int, headers: array<string, list<string>>, body: mixed}
     */
    public function toArray(): array
    {
        return ['status' => $this->status, 'headers' => $this->headers, 'body' => $this->body()];
    }

    private function decode(): mixed
    {
        if (trim($this->raw) === '') {
            return null;
        }

        try {
            return json_decode($this->raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw ResponseDecodingException::invalidJson($this, $exception);
        }
    }
}
