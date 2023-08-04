<?php

namespace yzh52521\cron;

use Carbon\Carbon;
use think\App;
use think\cache\Driver;
use think\facade\Db;
use yzh52521\cron\event\TaskFailed;
use yzh52521\cron\event\TaskProcessed;
use yzh52521\cron\event\TaskSkipped;

class Scheduler
{
    /** @var App */
    protected $app;

    /** @var Carbon */
    protected $startedAt;

    protected $tasks = [];

    protected $taskData = [];

    protected $type;

    protected $config;

    /** @var Driver */
    protected $cache;

    public function __construct(App $app)
    {
        $this->app    = $app;
        $this->config = $this->app->config->get('cron');
        $this->type   = strtolower($this->config['type']);
        $this->cache  = $app->cache->store($app->config->get('cron.store', null));
    }

    public function run()
    {
        $this->startedAt = Carbon::now();
        if ($this->type == 'mysql') {
            $this->tasks = $this->tasksSql($this->config['cache'] ?: 60);
        } else {
            $this->tasks = $this->config['tasks'];
        }
        foreach ($this->tasks as $k => $vo) {
            $taskClass            = $vo['task'];
            $expression           = empty($vo['expression']) ? false : $vo['expression'];
            $this->taskData['id'] = $k;
            if (is_subclass_of($taskClass, Task::class)) {

                /** @var Task $task */
                $task = $this->app->invokeClass($taskClass, [$expression, $this->cache, $this->app]);
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

                    $this->app->event->trigger(new TaskProcessed($task));
                }
            }
        }
    }

    /**
     * @param $task Task
     * @return bool
     */
    protected function serverShouldRun($task)
    {
        $key = $task->mutexName() . $this->startedAt->format('Hi');
        if ($this->cache->has($key)) {
            return false;
        }
        $this->cache->set($key, true, 60);
        return true;
    }

    protected function runSingleServerTask($task)
    {
        if ($this->serverShouldRun($task)) {
            $this->runTask($task);
        } else {
            $this->app->event->trigger(new TaskSkipped($task));
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
     */
    protected function runTask($task)
    {
        try {
            $task->run();
            $this->taskData['status_desc'] = $task->statusDesc;
            $this->taskData['next_time']   = $task->NextRun($this->startedAt);
            $this->taskData['last_time']   = $this->startedAt->format('Y-m-d H:i:s');
            $this->taskData['count']       = Db::raw('count+1');
            if ($this->type == 'mysql') {
                Db::table($this->config['table'])->update($this->taskData);
            } else {
                $this->cache->set('cron-' . $this->taskData['id'], $this->taskData, 0);
            }
        } catch (\Throwable $e) {
            $this->app->event->trigger(new TaskFailed($task, $e));
        }
    }
}