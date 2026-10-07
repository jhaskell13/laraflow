<?php

namespace Laraflow\Tests\Feature\Database;

use Illuminate\Support\Facades\Schema;
use Laraflow\Tests\TestCase;

class MigrationTest extends TestCase
{
    public function test_laraflow_tables_exist(): void
    {
        $this->assertTrue(
            Schema::hasTable('laraflow_workflow_executions')
        );

        $this->assertTrue(
            Schema::hasTable('laraflow_step_executions')
        );
    }

    public function test_workflow_executions_table_has_expected_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns(
                'laraflow_workflow_executions',
                [
                    'id',
                    'workflow',
                    'status',
                    'input',
                    'started_at',
                    'completed_at',
                    'failed_at',
                    'created_at',
                    'updated_at',
                ]
            )
        );
    }

    public function test_step_executions_table_has_expected_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns(
                'laraflow_step_executions',
                [
                    'id',
                    'workflow_execution_id',
                    'step',
                    'position',
                    'status',
                    'attempts',
                    'input',
                    'output',
                    'error',
                    'started_at',
                    'completed_at',
                    'next_retry_at',
                    'created_at',
                    'updated_at',
                ]
            )
        );
    }
}