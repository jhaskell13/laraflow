<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

abstract class Workflow
{
    /**
     * The ordered list of step classes that make up this workflow.
     *
     * @return list<class-string<Step>>
     */
    abstract public function steps(): array;
}
