<?php

namespace yzh52521\cron\event;

use yzh52521\cron\Task;

abstract class TaskEvent
{
    public $task;

    public function __construct(Task $task)
    {
        $this->task = $task;
    }

    public function getName()
    {
        return get_class($this->task);
    }
}