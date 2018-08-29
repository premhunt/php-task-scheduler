# Asynchronous process management for PHP

[![Build Status](https://travis-ci.org/gyselroth/mongodb-php-task-scheduler.svg?branch=master)](https://travis-ci.org/gyselroth/mongodb-php-task-scheduler)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/gyselroth/mongodb-php-task-scheduler/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/gyselroth/mongodb-php-task-scheduler/?branch=master)
[![Code Coverage](https://scrutinizer-ci.com/g/gyselroth/mongodb-php-task-scheduler/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/gyselroth/mongodb-php-task-scheduler/?branch=master)
[![Latest Stable Version](https://img.shields.io/packagist/v/gyselroth/mongodb-php-task-scheduler.svg)](https://packagist.org/packages/gyselroth/mongodb-php-task-scheduler)
[![GitHub release](https://img.shields.io/github/release/gyselroth/mongodb-php-task-scheduler.svg)](https://github.com/gyselroth/mongodb-php-task-scheduler/releases)
[![GitHub license](https://img.shields.io/badge/license-MIT-blue.svg)](https://raw.githubusercontent.com/gyselroth/mongodb-php-task-scheduler/master/LICENSE)

## Description
Asynchronous task scheduler for PHP using MongoDB as message queue. Execute asynchronous tasks the easy way.
This library has built-in support for clustered systems and multi core cpu. You can start up multiple worker nodes and they will load balance the available jobs with the principal first comes first serves. Each node will also spawn a (dynamically) configurable number of child processes to use all available resources. Moreover it is possible to schedule jobs at certain times, endless intervals as well as rescheduling if jobs fail.
This brings a real world implementation for asynchronous process management to PHP. You are also able to sync child tasks and much more nice stuff.

## Features

* Asynchronous tasks
* Cluster support
* Multi core support
* Load balancing
* Failover
* Scalable
* Sync tasks between each other
* Abort running tasks
* Timeout jobs
* Easy deployable on kubernets and other container orchestration platforms
* Retry and intervals
* Schedule tasks at specific times

# Table of Contents
  * [Description](#description)
  * [Features](#features)
  * [Why?](#why)
  * [How does it work (The short way please)?](#how-does-it-work-the-short-way-please)
  * [Requirements](#requirements)
  * [Download](#download)
  * [Changelog](#changelog)
  * [Contribute](#contribute)
  * [Documentation](#documentation)
    * [Create job](#create-job)
    * [Initialize scheduler](#initialize-scheduler)
    * [Create job queue](#create-job-queue)
    * [Create a job (mail example)](#Create-a-job-mail-example)
    * [Execute jobs](#execute-jobs)
    * [Advanced job options](#advanced-job-options)
    * [Add job if not exists](#add-job-if-not-exists)
    * [Advanced scheduler options](#advanced-scheduler-options)
    * [Advanced queue node options](#advanced-queue-node-options)
    * [Using a DIC (dependeny injection container)](#using-a-dic-dependeny-injection-container)
    * [Manage jobs](#manage-jobs)
    * [Get jobs](#get-jobs)
    * [Cancel job](#cancel-job)
    * [Modify job](#modify-job)

## Why?
PHP isn't a multithreaded language and neither can it handle (most) tasks asynchronously. Sure there are threads (pthreads) and forks (pcntl) but those are only usable in cli mode (Or you should them only be using in cli mode). Using this library you are able to write your code async, schedule them and let them execute asynchronously.

## How does it work (The short way please)?
A job is scheduled via a scheduler which will get appended in a central message queue using MongoDB. All Queue nodes will get notified in (soft) realtime that a new job is available.
One node will execute the task according the principle first come first serves. If no free slots are available the job will wait in the queue and get executed as soon as there is a free slot. 

## Requirements
* >= PHP7.1 
* MongoDB server >= 2.2
* PHP pcntl extension
* PHP posix extension

>**Note**: This library will only work on \*nix system. There is no windows support and there will never be.


## Download
The package is available at [packagist](https://packagist.org/packages/gyselroth/mongodb-php-task-scheduler)

To install the package via composer execute:
```
composer require gyselroth/mongodb-php-task-scheduler
```

## Changelog
A changelog is available [here](https://github.com/gyselroth/mongodb-php-task-scheduler/blob/master/CHANGELOG.md).

## Contribute
We are glad that you would like to contribute to this project. Please follow the given [terms](https://github.com/gyselroth/mongodb-php-task-scheduler/blob/master/CONTRIBUTING.md).

## Documentation

For a better understanding how this library works, we're going to implement a mail job. Of course you can implement any kind of job.

### Create job

It is quite easy to create as task, you just need to implement TaskScheduler\JobInterface. 
In this example we're going to implement a job called MailJob which sends mail via zend-mail.

>**Note**: You can use TaskScheduler\AbstractJob to implement the required default methods by TaskScheduler\JobInterface.
The only thing then you need to implement is start() which does the actual job (sending mail).

```php
class MailJob extends TaskScheduler\AbstractJob
{
    /**
     * {@inheritdoc}
     */
    public function start(): bool
    {
        $transport = new Zend\Mail\Transport\Sendmail();
        $mail = Message::fromString($this->data);
        $this->transport->send($mail);

        return true;
    }
}
```

### Initialize scheduler

You need an instance of a MongoDB\Database and a Psr\Log\LoggerInterface compatible logger to initialize the scheduler.

```php
$mongodb = new MongoDB\Client('mongodb://localhost:27017');
$logger = new \A\Psr4\Compatible\Logger();
$scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);
```

### Append job

Now let us create a mail and deploy it to the task scheduler which we have initialized right before:

```php
$mail = new Message();
$mail->setSubject('Hello...');
$mail->setBody('World');
$mail->setFrom('root@localhost', 'root');

$scheduler->addJob(MailJob::class, $mail->toString());
```

This is the whole magic, our scheduler now got its first job, awesome!


### Execute jobs

But now we need to execute those queued jobs.  
That's where the queue nodes come into play.
Those nodes listen in (soft) realtime for new jobs and will load balance those jobs. 

#### Create queue worker factory

You will need to create your own worker node factory in your app namespace which gets called to spawn new child processes.
This factory gets called during a new fork is spawned. This means if build() get called you are in a new process and you will need to bootstrap your application 
from scatch. In this simple example we will manually create a new mongodb connection, create a new scheduler and logger instance and finally return a new TaskScheduler\Worker instance.

For better understanding: for example there is a configuration file where the mongodb uri is stored, in build() you will need to parse this configuration again and create a new mongodb instance.
Or you may be using a dic, the dic need to be created from scratch in build() (A new dependency tree). You may pass an instance of a dic (compatible to Psr\Container\ContainerInterface) as fourth argument to TaskScheduler\Worker.

```php
class WorkerFactory extends TaskScheduler\WorkerFactoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function build(): TaskScheduler\Worker
    {
        $mongodb = new MongoDB\Client('mongodb://localhost:27017');
        $logger = new \A\Psr4\Compatible\Logger();
        $scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);

        return new TaskScheduler\Worker($scheduler, $mongodb->mydb, $logger);
    }
}
```

>**Note**: Theoretically you can use existing connections, objects and so on by setting those via the constructor of your worker factory since the factory gets initialized in main(). But this will likely lead to errors and strange app behaviours and is not supported.

#### Create queue node

Let us write a new queue node. The queue node must be started as a separate process!
You should provide an easy way to start such queue nodes, there are multiple ways to achieve this. The easiest way
is to just create a single php script which can be started via cli.


```php
$mongodb = new MongoDB\Client('mongodb://localhost:27017');
$logger = new \A\Psr4\Compatible\Logger();
$scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);
$worker_factory = My\App\WorkerFactory(); #An instance of our previously created worker factory
$queue = new TaskScheduler\Queue($scheduler, $mongodb, $worker_factory, $logger);
```

And then start the magic:

```php
$queue->process();
```

>**Note**: TaskScheduler\Queue::process() is a blocking call.


Our mail gets sent as soon as a queue node is running. 

Usually you want those nodes running at all times! They act like invisible execution nodes behind your app.

### Manage jobs

#### Get jobs
You may want to retrieve all scheduled jobs:

```php
$scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);
$scheduler->getJobs();
```

By default you will receive all jobs with the status:
* Queue::STATUS_WAITING,
* Queue::STATUS_PROCESSING
* Queue::STATUS_POSTPONED

You may pass an optional query to query specific jobs.

```php
$scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);
$jobs = $scheduler->getJobs([
    'status' => TaskScheduler\JobInterface::STATUS_DONE,
    '$or' => [
        ['class' => 'MyApp\\MyTask1'],
        ['class' => 'MyApp\\MyTask2'],
    ]
']);

foreach($jobs as $job) {
    echo $job->getId()." done\n";
}
```

#### Cancel job
You are able to cancel a scheduled job by passing the job id to the scheduler:
```php
$scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);
$scheduler->cancelJob(MongoDB\BSON\ObjectId $job_id);
```

It is **not** possible to cancel running jobs (jobs with status Queue::STATUS_PROCESSING).

#### Modify jobs
It is **not** possible to modify a scheduled job by design. You need to cancel the job and append a new one.

### Asynchronous programming

Have a look at this example:

```php
$scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);
$scheduler->addJob(MyTask::class, 'foobar')
  ->wait();
```

This will force main() (Your process) to wait until the task `MyTask::class` was executed. (Either with status DONE, FAILED or CANCELED).

Here is more complex example:
```php
$scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);
$jobs = [];
$jobs[] = $scheduler->addJob(MyTask::class, 'foobar');
$jobs[] = $scheduler->addJob(MyTask::class, 'barfoo');
$jobs[] = $scheduler->addJob(OtherTask::class, 'barefoot');

foreach($jobs as $job) {
    $job->wait();
}

//some other important stuff here
```

This will wait for all three jobs to be finished before continuing.

**Important note**:
If you are programming in http mode (incoming http requests) and your app needs to deploy tasks it is good practice not to wait!. 
Best practics is to return a [HTTP 202 code](https://httpstatuses.com/202) instead. If the client needs to know the result of those jobs you may return 
the process id's and send a 2nd reqeuest which then waits and returns the status of those jobs.

```php
$scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);
$jobs = $scheduler->getJobs([
    '_id' => ['$in' => $job_ids_from_http_request]
]);

foreach($jobs as $job) {
    $job->wait();
}
```

**Important note**:
If you are programming in http mode (incoming http requests) and your app needs to deploy tasks it is good practice not to wait!. 
Best practics is to return a [HTTP 202 code](https://httpstatuses.com/202) instead. If the client needs to know the result of those jobs you may return 
the process id's and send a 2nd reqeuest which then waits and returns the status of those jobs.

### Advanced scheduler options
TaskScheduler\Scheduler::addJob() also accepts a third option (options) which let you append more advanced options for the scheduler:

| Option  | Default | Type | Description |
| ------------- | ------------- |
| `at`  | `null`  | ?int | Accepts a specific unix time which let you specify the time at which the job should be executed. The default is immediatly or better saying as soon as there is a free slot. |
| `interval`  | `-1`  | int | You may specify a job interval (in secconds) which is usefuly for jobs which need to be executed in a specific interval, for example cleaning a temporary directory. The default is `-1` which means no interval at all, `0` would mean execute the job immediatly again (But be careful with `0`, this could lead to huge cpu usage depending what job you're executing). Configuring `3600` would mean the job will be executed hourly. |
| `retry`  | `0`  | int | Specifies a retry interval if the job fails to execute. The default is `0` which means do not retry. |
| `retry_interval`  | `300`  | int | This options specifies the time (in secconds) between job retries. The default is `300` which is 5 minutes. |
| `ignore_max_children`  | `false`  | bool | You may specify `true` for this option to spawn a new child process. This will ignore the configured max_children option for the queue node. The queue node will always fork a new child if job with this option is scheduled. Use this option wisely! It makes perfectly sense for jobs which make blocking calls, for example a listener which listens for local filesystem changes (inotify). A job with this enabled option should only consume as little cpu/memory as possible. |
| `timeout`  | `0`  | int | Specify a timeout in secconds which will forcly terminate the job after the given time has passed. The default `0` means no timeout at all. A timeout job will get rescheduled if retry is not `0` and will marked as timed out.  |

Let us add our mail job example again with some custom options:
**Note:** We are using the OPTION_ constansts here, you may also just use the names documented above.

```php
$mongodb = new MongoDB\Client('mongodb://localhost:27017');
$logger = new \A\Psr4\Compatible\Logger();
$scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);

$mail = new Message();
$mail->setSubject('Hello...');
$mail->setBody('World');
$mail->setFrom('root@localhost', 'root');

$scheduler->addJob(MailJob::class, $mail->toString(), [
    TaskScheduler\Scheduler::OPTION_AT => time()+3600,
    TaskScheduler\Scheduler::OPTION_RETRY => 3,
    TaskScheduler\Scheduler::OPTION_RETRY_INTERVAL => 60,
]);
```

This will queue our mail to be executed in one hour from now and it will re-schedule the job up to three times if it fails with an interval of one minute.

### Add job if not exists
What you also can do is adding the job only if it has not been queued yet.
Instead using `addJob()` you can use `addJobOnce()`, the scheduler then verifies if it got the same job already queued. If not, the job gets added.
The scheduler compares the type of job (`MailJob` in this case) and the data submitted (`$mail->toString()` in this case).

**Note:** The job gets also re-added if advanced options changed (Or are not the same).

```php
$scheduler->addJobOnce(MailJob::class, $mail->toString(), [
    TaskScheduler\Scheduler::OPTION_AT => time()+3600,
    TaskScheduler\Scheduler::OPTION_RETRY => 3,
]);
```

### Advanced scheduler options

You may set those job options as global defaults for the whole scheduler.
Custom options and defaults can be set for jobs during initialization or by calling Scheduler::setOptions().

```php
$mongodb = new MongoDB\Client('mongodb://localhost:27017');
$logger = new \A\Psr4\Compatible\Logger();
$scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger, null, [
    TaskScheduler\Scheduler::OPTION_COLLECTION_NAME => 'jobs',
    TaskScheduler\Scheduler::OPTION_QUEUE_SIZE => 10000000,
    TaskScheduler\Scheduler::OPTION_DEFAULT_RETRY => 3
]);
```

You may also change those options afterwards:
```php
$scheduler->setOptions([
    TaskScheduler\Scheduler::OPTION_DEFAULT_RETRY => 2
]);
```

| Name  | Default | Type | Description |
| ------------- | ------------- |
| `job_queue`  | `taskscheduler.jobs`  | string | The MongoDB collection which acts as job message queue. |
| `job_queue_size`  | `100000`  | int | The maximum size of jobs, if reached the first jobs get overwritten by new ones. |
| `event_queue`  | `taskscheduler.events`  | string | The MongoDB collection which acts as event message queue. |
| `event_queue_size`  | `500000`  | int | The maximum size of events, if reached the first events get overwritten by new ones. This value should usually be a multiplicated value of `job_queue_size` since a job can have more events. |
| `default_at`  | `null`  | ?int | Define a default execution time for **all** jobs. This relates only for newly added jobs. The default is immediatly or better saying as soon as there is a free slot. |
| `default_interval`  | `-1`  | int | Define a default interval for **all** jobs. This relates only for newly added jobs. The default is `-1` which means no interval at all. |
| `default_retry`  | `0`  | int | Define a default retry interval for **all** jobs. This relates only for newly added jobs. There are now retries by default for failed jobs. |
| `default_retry_interval`  | `300`  | int | This options specifies the time (in secconds) between job retries. This relates only for newly added jobs. The default is `300` which is 5 minutes. |

### Advanced queue node options

You may change process management related options for queue nodes.
Custom options and defaults can be set for jobs during initialization or if you call Queue::setOptions().
 
```php
$mongodb = new MongoDB\Client('mongodb://localhost:27017');
$logger = new \A\Psr4\Compatible\Logger();
$scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);
$worker_factory = My\App\WorkerFactory();
$queue = new TaskScheduler\Queue($scheduler, $mongodb, $worker_factory, $logger, null, [
    TaskScheduler\Scheduler::OPTION_MIN_CHILDREN => 10,
    TaskScheduler\Scheduler::OPTION_PM => 'static' 
]);
```

You may also change those options afterwards:
 ```php
$scheduler->setOptions([
     TaskScheduler\Scheduler::OPTION_MIN_CHILDREN => 20
]);
```

| Name  | Default | Type | Description |
| ------------- | ------------- |
| `pm`  | `dynamic`  | string | You may change the way how fork handling is done. There are three modes, see bellow this table. |
| `min_children`  | `1`  | int | The minimum number of child processes. |
| `max_children`  | `2`  | int | The maximum number of child processes. |

Process management modes (`pm`):

* dynamic (start min_children forks at startup and dynamically create new children if required until max_children is reached)
* static (start min_children nodes, (max_children is ignored))
* ondemand (Do not start any children at startup but spawn new children if required until max_children is reached (min_children is ignored))

The default is `dynamic`. Usually `dynamic` makes sense. You may need `static` in a container provisioned world whereas the number of queue nodes is determined
from the number of outstanding jobs. For example [Kubernetes autoscaling](https://cloud.google.com/kubernetes-engine/docs/tutorials/custom-metrics-autoscaling).

>**Note**: The number of actual child processes can be higher if jobs are scheduled with the option Scheduler::OPTION_IGNORE_MAX_CHILDREN.

### Using a DIC (dependeny injection container)
Optionally one can pass a Psr\Container\ContainerInterface to the worker nodes which then get called to create job instances.
Here is our WorkerFactory from before but this time it passes an instance of a PSR-11 container to worker nodes.

```php
class WorkerFactory extends TaskScheduler\WorkerFactoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function build(): TaskScheduler\Worker
    {
        $mongodb = new MongoDB\Client('mongodb://localhost:27017');
        $logger = new \A\Psr4\Compatible\Logger();
        $scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);
        $dic = new \A\Psr11\Compatible\Dic();

        return new TaskScheduler\Worker($scheduler, $mongodb->mydb, $logger, $dic);
    }
}
```

If a container is passed, the scheduler will request job instances through the dic.

```php
$mongodb = new MongoDB\Client('mongodb://localhost:27017');
$logger = new \A\Psr4\Compatible\Logger();
$dic = new \A\Psr11\Compatible\Container();
$scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);
$worker_factory = My\App\WorkerFactory();
$queue = new TaskScheduler\Queue($scheduler, $mongodb, $worker_factory, $logger);
```
