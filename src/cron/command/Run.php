<?php

namespace yzh52521\cron\command;

use Carbon\Carbon;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

use yzh52521\cron\Task;

class Run extends Command
{
    protected $type;

    protected $config;

    protected $startedAt;

    protected $taskData = [];

    protected function initialize(Input $input, Output $output)
    {
        $this->config = $this->app->config->get('cron');
        $this->type   = strtolower($this->config['type']);
        $this->startedAt = Carbon::now();
    }

    protected function configure()
    {

        $this->setName('cron:run')
            ->addOption('memory', null, Option::VALUE_OPTIONAL, 'The memory limit in megabytes', 128)
            ->setDescription('Running a scheduled task');
    }

    public function execute(Input $input, Output $output)
    {
        //防止页面超时,实际上CLI命令行模式下 本身无超时时间
        ignore_user_abort(true);
        set_time_limit(0);
        if ($this->type == 'file') {
            $tasks = $this->config['tasks'];
            if (empty($tasks)) {
                $output->comment("No tasks to execute");
                return false;
            }
        } elseif ($this->type == 'mysql' && Db::execute("SHOW TABLES LIKE '{$this->config['table']}'")) {
            $tasks = $this->tasksSql($this->config['cache'] ?: 60);
            if (empty($tasks)) {
                $output->comment("No tasks to execute");
                return false;
            }
        } else {
            $output->error("Please first set config type is mysql and execute: php think cron:install");
            return false;
        }
        foreach ($tasks as $k => $vo) {
            $taskClass = $vo['task'];
            $expression  = empty($vo['expression']) ? false : $vo['expression'];
            $this->taskData['id'] = $k;
            if (is_subclass_of($taskClass, Task::class)) {
                /** @var Task $task */
                $task = $this->app->invokeClass($taskClass,[$expression]);
                if ($this->type == 'mysql') {
                    $task->payload = json_decode($vo['data'], true);
                } else {
                    $task->payload = empty($vo['data']) ? [] : $vo['data'];
                }

                if ($task->isDue()) {
                    if (!$task->filtersPass()) {
                        continue;
                    }

                    if ($task->onOneServer) {
                        $this->runSingleServerTask($task);
                    } else {
                        $this->runTask($task);
                    }
                    $output->writeln("<info>Task {$taskClass} run at " . $this->startedAt . "</info>");
                }
            }
        }
    }


    protected function tasksSql($time = 60)
    {
        return Db::table($this->config['table'])
            ->cache(true, $time)
            ->where('status', 1)
            ->order('sort', 'asc')
            ->column(
                'title,expression,task,data',
                'id'
            );
    }

    /**
     * @param $task Task
     * @return bool
     */
    protected function serverShouldRun($task)
    {
        $key = $task->mutexName() . $this->startedAt->format('H:i');
        if ($this->app->cache->has($key)) {
            return false;
        }
        $this->app->cache->set($key, true, 60);
        return true;
    }

    protected function runSingleServerTask($task)
    {
        if ($this->serverShouldRun($task)) {
            $this->runTask($task);
        } else {
            $this->output->writeln(
                '<info>Skipping task (has already run on another server):</info> ' . get_class($task)
            );
        }
    }

    /**
     * @param $task Task
     */
    protected function runTask($task)
    {
        $task->run();
        $this->taskData['status_desc'] = $task->statusDesc;
        $this->taskData['next_time']   = $task->NextRun($this->startedAt);
        $this->taskData['last_time']   = $this->startedAt;
        $this->taskData['count']       = Db::raw('count+1');
        if ($this->type == 'mysql') {
            Db::table($this->config['table'])->update($this->taskData);
        }else{
            $this->app->cache->set('cron-'.$this->taskData['id'], $this->taskData, 0);
        }
    }
}
