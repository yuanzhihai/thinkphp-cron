<?php

namespace yzh52521\cron\event;

class TaskFailed extends TaskEvent
{
    public $exception;

    public function __construct($task, $exception)
    {
        parent::__construct($task);
        $this->exception = $exception;
    }
}