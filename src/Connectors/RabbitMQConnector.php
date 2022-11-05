<?php

namespace Jenky\Larabbit\Connectors;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\Events\WorkerStopping;
use InvalidArgumentException;
use Jenky\Larabbit\ConnectionResolver;
use Jenky\Larabbit\Queue\RabbitMQQueue;
use PhpAmqpLib\Connection\AbstractConnection;

class RabbitMQConnector implements ConnectorInterface
{
    /**
     * @var \Illuminate\Contracts\Events\Dispatcher
     */
    protected $dispatcher;

    /**
     * @var callable
     */
    protected $resolver;

    /**
     * Create new connector instance.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @param  callable  $resolver
     * @return void
     */
    public function __construct(Dispatcher $dispatcher, $resolver)
    {
        $this->dispatcher = $dispatcher;
        $this->resolver = $resolver;
    }

    /**
     * Establish a queue connection.
     *
     * @param  array  $config
     * @return \Illuminate\Contracts\Queue\Queue
     */
    public function connect(array $config)
    {
        $queue = new RabbitMQQueue(
            call_user_func($this->resolver, $config['connection'] ?? []),
            $config['queue'],
            $config['after_commit'] ?? false
        );

        $this->dispatcher->listen(WorkerStopping::class, function () use ($queue) {
            $queue->close();
        });

        return $queue;
    }
}
