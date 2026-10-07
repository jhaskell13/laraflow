<?php

namespace Laraflow\Workflow;

abstract class Workflow
{
    abstract public function steps(): array;
}