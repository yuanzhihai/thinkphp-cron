<?php

namespace yzh52521\cron;

use Carbon\Carbon;
use Closure;
use Cron\CronExpression;
use DateTimeZone;
use think\Cache;

abstract class Task
{

    use ManagesFrequencies;

    /** @var DateTimeZone|string 时区 */
    public $timezone;

    /** @var string 任务周期 */
    public $expression = '* * * * *';

    /** @var bool 任务是否可以重叠执行 */
    public $withoutOverlapping = false;

    /** @var int 最大执行时间(重叠执行检查用) */
    public $expiresAt = 1440;

    /** @var bool 分布式部署 是否仅在一台服务器上运行 */
    public $onOneServer = false;

    protected $filters = [];
    protected $rejects = [];
    //@var array 附加参数
    public $payload = [];

    //@var string 任务处理结果
    public $statusDesc = null;

    /** @var Cache */
    protected $cache;

    public function __construct($expression = false,Cache $cache)
    {
        $this->cache = $cache;
        if ($expression) {
            $this->expression = $expression;
        } else {
            $this->configure();
        }
    }

    /**
     * 是否到期执行
     * @return bool
     */
    public function isDue()
    {
        $date = Carbon::now( $this->timezone );

        return CronExpression::factory( $this->expression )->isDue( $date->toDateTimeString() );
    }

    /**
     * 下一次执行
     * @return bool
     */
    public function NextRun($datatime = null)
    {
        return CronExpression::factory( $this->expression )->getNextRunDate( $datatime )->format( 'Y-m-d H:i:s' );
    }

    /**
     * 配置任务
     */
    protected function configure()
    {
    }

    /**
     * 执行任务
     * @return mixed
     */
    abstract protected function execute();

    final public function run()
    {
        if ($this->withoutOverlapping &&
            !$this->createMutex()) {
            return;
        }

        register_shutdown_function( function () {
            $this->removeMutex();
        } );

        try {
            $this->execute();
        } finally {
            $this->removeMutex();
        }
    }

    /**
     * 过滤
     * @return bool
     */
    public function filtersPass()
    {
        foreach ( $this->filters as $callback ) {
            if (!call_user_func( $callback )) {
                return false;
            }
        }

        foreach ( $this->rejects as $callback ) {
            if (call_user_func( $callback )) {
                return false;
            }
        }

        return true;
    }

    /**
     * 任务标识
     */
    public function mutexName()
    {
        return 'task-'.sha1( static::class );
    }

    protected function removeMutex()
    {
        return $this->cache->delete( $this->mutexName() );
    }

    protected function createMutex()
    {
        $name = $this->mutexName();

        return $this->cache->set( $name,time(),$this->expiresAt );
    }

    protected function existsMutex()
    {
        if ($this->cache->has( $this->mutexName() )) {
            $mutex = $this->cache->get( $this->mutexName() );
            return $mutex + $this->expiresAt > time();
        }
        return false;
    }

    public function when(Closure $callback)
    {
        $this->filters[] = $callback;

        return $this;
    }

    public function skip(Closure $callback)
    {
        $this->rejects[] = $callback;

        return $this;
    }

    public function withoutOverlapping($expiresAt = 1440)
    {
        $this->withoutOverlapping = true;

        $this->expiresAt = $expiresAt;

        return $this->skip( function () {
            return $this->existsMutex();
        } );
    }

    public function onOneServer()
    {
        $this->onOneServer = true;

        return $this;
    }
}
