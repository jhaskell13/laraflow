<?php

declare(strict_types=1);

namespace Laraflow\Tests\Unit\Support;

use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Laraflow\Support\ErrorMessage;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ErrorMessageTest extends TestCase
{
    public function test_an_ordinary_exception_keeps_its_message(): void
    {
        $this->assertSame('plain', ErrorMessage::of(new RuntimeException('plain')));
    }

    /**
     * The form the soak actually produced. Laravel builds a
     * DeadlockException out of the QueryException's *message*, so the
     * bound values travel into an exception that is not a
     * QueryException — the chain is what has to be walked.
     */
    public function test_a_deadlock_wrapping_a_query_exception_loses_the_values_too(): void
    {
        $query = new QueryException(
            'soak',
            'insert into "laraflow_workflow_executions" ("input") values (?)',
            ['{"card":"tok_SENTINEL123"}'],
            new PDOException('SQLSTATE[HY000]: General error: 5 database is locked'),
        );

        $deadlock = new DeadlockException($query->getMessage(), 0, $query);

        $this->assertStringContainsString('tok_SENTINEL123', $deadlock->getMessage());

        $retained = ErrorMessage::of($deadlock);

        $this->assertStringContainsString('database is locked', $retained);
        $this->assertStringContainsString('values (?)', $retained);
        $this->assertStringNotContainsString('tok_SENTINEL123', $retained);
    }

    public function test_a_wrapper_that_quotes_the_query_message_loses_the_values(): void
    {
        $query = new QueryException(
            'soak',
            'update "t" set "a" = ?',
            ['tok_SENTINEL123'],
            new PDOException('SQLSTATE[HY000]: deadlock detected'),
        );

        $wrapper = new RuntimeException('Could not checkpoint: '.$query->getMessage(), 0, $query);

        $retained = ErrorMessage::of($wrapper);

        $this->assertStringStartsWith('Could not checkpoint: ', $retained);
        $this->assertStringContainsString('set "a" = ?', $retained);
        $this->assertStringNotContainsString('tok_SENTINEL123', $retained);
    }

    public function test_a_query_exception_keeps_the_driver_error_and_the_statement_but_not_its_values(): void
    {
        $exception = new QueryException(
            'mysql',
            'insert into "t" ("a", "b") values (?, ?)',
            ['secret-value', 42],
            new PDOException('SQLSTATE[23000]: Integrity constraint violation'),
        );

        // Laravel's own message carries the values.
        $this->assertStringContainsString('secret-value', $exception->getMessage());

        $retained = ErrorMessage::of($exception);

        $this->assertSame(
            'SQLSTATE[23000]: Integrity constraint violation (Connection: mysql, SQL: insert into "t" ("a", "b") values (?, ?))',
            $retained,
        );
    }
}
