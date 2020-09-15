# think-cron 计划任务

## 安装方法
```
composer require yzh52521/thinkphp-cron
```

## 使用方法

### 创建任务类

```
<?php

namespace app\task;

use yzh52521\cron\Task;

class DemoTask extends Task
{

    public function configure()
    {
        $this->daily(); //设置任务的周期，每天执行一次，更多的方法可以查看源代码，都有注释
    }

    /**
     * 执行任务
     * @return mixed
     */
    protected function execute()
    {
        //...具体的任务执行
    }
}

```

### 配置
> 配置文件位于 application/config/cron.php

```
return [
    'tasks' => [
        \app\task\DemoTask::class, //任务的完整类名
    ]
];
```

### 任务监听

#### 两种方法：

> 方法一 (推荐)

起一个常驻进程，可以配合supervisor使用
~~~
php think cron:schedule start --daemon
~~~
##### 创建 supervisor 
```
[program:php]
command= /usr/bin/php think cron:schedule start; 被监控进程
directory=/home/wwwroot/shabi.in
process_name=%(program_name)s
numprocs=1 ;启动几个进程 别改 扩展限制了一个进程运行
autostart=true ;随着supervisord的启动而启动
autorestart=true ;自动启动
startsecs=1 ;程序重启时候停留在runing状态的秒数
startretries=10 ;启动失败时的最多重试次数
redirect_stderr=true ;重定向stderr到stdout
stdout_logfile=/root/supervisor.log ;stdout文件
```

> 方法二

在系统的计划任务里添加
~~~
* * * * * php /path/to/think cron:run >> /dev/null 2>&1
~~~
## 特别鸣谢
- 使用了[yunwuxin/think-cron](https://packagist.org/packages/yunwuxin/think-cron/ "创建自定义指令")项目中的部分代码

## 写在最后
- 代码中有很多不成熟的地方，期待您的issue。最好能fork，将您的想法贡献出来。让这个项目更适应更多的场景。
- 邮箱：i@shabi.in
