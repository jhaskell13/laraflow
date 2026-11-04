<?php

declare(strict_types=1);

namespace Laraflow\Console\Concerns;

use Closure;
use Illuminate\Console\Command;
use Laraflow\Support\ErrorRedactor;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * @internal `--json` on a Laraflow command: one JSON document on
 * standard output, nothing else, the same exit code the human form
 * returns. Every document carries `command` and `ok`; a refusal is
 * `{"ok": false, "error": "..."}` with the message the human form
 * would have printed. Rendering is decided once, here, so no command
 * can print half a table and then a document.
 *
 * That promise covers the failures nobody anticipated too: execute()
 * below is the one place an unexpected exception under `--json`
 * becomes `{"ok": false, "error": ..., "exception": ...}` and exit 1,
 * instead of Symfony's rendered stack trace on a stream a pipeline is
 * about to hand to jq (Milestone 10 audit). The human form is
 * untouched — a person at a terminal wants the trace.
 *
 * @mixin Command
 */
trait OutputsJson
{
    /**
     * Symfony's Command::run() calls this with the input already bound,
     * so wantsJson() can be asked here. Overriding it in the trait puts
     * the catch-all under every command that speaks JSON without each
     * of them remembering to wrap its own handle().
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (! $this->wantsJson()) {
            return parent::execute($input, $output);
        }

        try {
            return parent::execute($input, $output);
        } catch (Throwable $exception) {
            // The class, not the trace: enough for a machine to branch
            // on and a person to search for, and nothing that leaks a
            // file layout into a CI log.
            $this->emitJson([
                'error' => $this->redactedMessage($exception),
                'exception' => $exception::class,
            ], false);

            return Command::FAILURE;
        }
    }

    /**
     * An exception's message as Laraflow would retain it: through the
     * configured ErrorRedactor, so a step exception carrying a token is
     * no more visible on a console document than on the execution row.
     * A redactor must not throw; if one does anyway, a fixed placeholder
     * stands in rather than the raw message the redactor was there to
     * keep out.
     */
    protected function redactedMessage(Throwable $exception): string
    {
        $message = $exception->getMessage();

        if (! $this->laravel->bound(ErrorRedactor::class)) {
            return $message;
        }

        try {
            return $this->laravel->make(ErrorRedactor::class)->redact($exception, $message);
        } catch (Throwable) {
            return '[message withheld: the configured error redactor failed]';
        }
    }

    protected function wantsJson(): bool
    {
        return $this->getDefinition()->hasOption('json') && (bool) $this->option('json');
    }

    /**
     * Answer with $document when `--json` was asked for, or by running
     * $human otherwise, and return $code either way.
     *
     * @param  array<string, mixed>  $document
     * @param  Closure(): void  $human
     */
    protected function respond(array $document, Closure $human, int $code = Command::SUCCESS): int
    {
        if ($this->wantsJson()) {
            $this->emitJson($document, $code === Command::SUCCESS);

            return $code;
        }

        $human();

        return $code;
    }

    /**
     * A refusal: the error component, or the error document.
     *
     * @param  array<string, mixed>  $extra  Fields that help a machine act on the refusal.
     */
    protected function refuse(string $message, array $extra = []): int
    {
        if ($this->wantsJson()) {
            $this->emitJson(['error' => $message] + $extra, false);

            return Command::FAILURE;
        }

        $this->components->error($message);

        return Command::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    protected function emitJson(array $document, bool $ok): void
    {
        $document = ['command' => $this->getName(), 'ok' => $ok] + $document;

        $this->output->writeln(json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }
}
