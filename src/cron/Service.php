<?php

namespace yzh52521\cron;

use Composer\InstalledVersions;
use Swoole\Timer;
use think\swoole\Manager;
use yzh52521\cron\command\MySql;
use yzh52521\cron\command\Run;
use yzh52521\cron\command\Schedule;

class Service extends \think\Service
{

    public function boot()
    {
        $this->commands([
            MySql::class,
            Run::class,
            Schedule::class,
        ]);
        $version = InstalledVersions::getPrettyVersion('topthink/think-swoole');
        if (strpos($version, '4.0') !== false) {
            $this->app->event->listen('swoole.init', function (Manager $manager) {
                $manager->addWorker(function () use ($manager) {
                    Timer::tick(60 * 1000, function () use ($manager) {
                        $manager->runWithBarrier([$manager, 'runInSandbox'], function (Scheduler $scheduler) {
                            $scheduler->run();
                        });
                    });
                }, "cron");
            });
        } else {
            $this->app->event->listen('swoole.init', function (Scheduler $scheduler) {
                Timer::tick(60 * 1000, function () use ($scheduler) {
                    $scheduler->run();
                });
            });
        }
    }
}
