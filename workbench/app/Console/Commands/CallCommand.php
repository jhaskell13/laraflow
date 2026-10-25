<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use JsonException;
use Laraflow\Core\LaraflowManager;
use Laraflow\Events\ConnectorRequestFailed;
use Laraflow\Events\ConnectorRequestSent;
use Laraflow\Exceptions\ConnectorException;
use Laraflow\Integration\ConnectorRequest;

/**
 * Sends one request through a connector and shows what the framework
 * did with it: the mapped error type, the wire attempts, the duration,
 * and the events it announced.
 *
 * It talks to the real provider with whatever credential the playground
 * environment holds, which is exactly why it lives in workbench/ and not
 * in the package: a command that fires an arbitrary request at a
 * production API has no business being installable into an application.
 * With no credential configured it demonstrates the configuration
 * error instead, which is also worth seeing once.
 */
class CallCommand extends Command
{
    protected $signature = 'playground:call
        {connector : A registered connector, e.g. github or slack}
        {uri : The request URI, relative to the connector, e.g. /user}
        {--method=GET : HTTP method}
        {--connection= : A named connection; the default when omitted}
        {--json= : A JSON body, for POST and friends}
        {--throw : Throw the mapped error instead of returning it}';

    protected $description = 'Send one request through a connector and show what happened';

    public function handle(LaraflowManager $laraflow, Dispatcher $events): int
    {
        $name = (string) $this->argument('connector');
        $connection = $this->option('connection');
        $connection = is_string($connection) && $connection !== '' ? $connection : null;

        try {
            $body = $this->body();
        } catch (JsonException $exception) {
            $this->components->error("--json is not valid JSON: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $request = new ConnectorRequest(
            strtoupper((string) $this->option('method')),
            (string) $this->argument('uri'),
            $body === null ? [] : ['json' => $body],
        );

        // The events are what an application would listen to; show
        // them, so the demonstration is of the contract rather than of
        // this command's own reading of the response.
        $events->listen(ConnectorRequestSent::class, function (ConnectorRequestSent $event): void {
            $this->components->twoColumnDetail('Event', 'ConnectorRequestSent');
            $this->components->twoColumnDetail('  status', (string) $event->status);
            $this->components->twoColumnDetail('  successful', $event->successful ? 'yes' : 'no');
            $this->components->twoColumnDetail('  attempts', (string) $event->attempts);
            $this->components->twoColumnDetail('  duration', "{$event->durationMs} ms");
        });

        $events->listen(ConnectorRequestFailed::class, function (ConnectorRequestFailed $event): void {
            $this->components->twoColumnDetail('Event', 'ConnectorRequestFailed');
            $this->components->twoColumnDetail('  error', class_basename($event->errorClass));
            $this->components->twoColumnDetail('  duration', "{$event->durationMs} ms");
        });

        try {
            $connector = $laraflow->connector($name, $connection);
            $response = $connector->request($request);

            if ($this->option('throw')) {
                $response->throw();
            }
        } catch (ConnectorException $exception) {
            $this->components->error(class_basename($exception).': '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->twoColumnDetail('Connector', $connector->name().' ['.($connection ?? 'default').']');
        $this->components->twoColumnDetail('Request', "{$request->method} {$request->path()}");
        $this->components->twoColumnDetail('Status', (string) $response->status());

        $error = $response->error();

        if ($error !== null) {
            $this->components->twoColumnDetail('Mapped error', class_basename($error));
            $this->components->twoColumnDetail('Message', $error->getMessage());
        }

        $this->newLine();
        $this->line($this->summarise($response->body()));

        return $error === null ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws JsonException
     */
    protected function body(): ?array
    {
        $json = $this->option('json');

        if (! is_string($json) || trim($json) === '') {
            return null;
        }

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : ['value' => $decoded];
    }

    /**
     * The body, trimmed to a screenful. The point is to see its shape,
     * not to page through it.
     */
    protected function summarise(mixed $body): string
    {
        if ($body === null) {
            return '(empty body)';
        }

        $text = is_string($body) ? $body : (string) json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $lines = explode("\n", $text);

        if (count($lines) > 40) {
            $lines = [...array_slice($lines, 0, 40), '… ('.(count($lines) - 40).' more lines)'];
        }

        return implode("\n", $lines);
    }
}
