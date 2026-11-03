<?php

declare(strict_types=1);

namespace Laraflow\Console\Concerns;

use Closure;
use Illuminate\Console\Command;

/**
 * @internal `--json` on a Laraflow command: one JSON document on
 * standard output, nothing else, the same exit code the human form
 * returns. Every document carries `command` and `ok`; a refusal is
 * `{"ok": false, "error": "..."}` with the message the human form
 * would have printed. Rendering is decided once, here, so no command
 * can print half a table and then a document.
 *
 * @mixin Command
 */
trait OutputsJson
{
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
