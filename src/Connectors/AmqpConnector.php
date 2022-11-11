<?php

declare(strict_types=1);

namespace Jenky\Larabbit\Connectors;

use Enqueue\AmqpTools\DelayStrategy;
use Enqueue\AmqpTools\DelayStrategyAware;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Interop\Amqp\AmqpContext;
use Jenky\Larabbit\Contracts\ConnectionFactory;
use Jenky\Larabbit\Queue\AmqpQueue;

class AmqpConnector implements ConnectorInterface
{
    /**
     * The AMQP connection factory instance.
     *
     * @var \Jenky\Larabbit\Contracts\ConnectionFactory
     */
    protected $factory;

    /**
     * Create new AMQP queue connector instance.
     *
     * @param  \Jenky\Larabbit\Contracts\ConnectionFactory  $factory
     * @return void
     */
    public function __construct(ConnectionFactory $factory)
    {
        $this->factory = $factory;
    }

    /**
     * Establish a queue connection.
     *
     * @param  array  $config
     * @return \Illuminate\Contracts\Queue\Queue
     */
    public function connect(array $config)
    {
        return new AmqpQueue(
            $this->createConnection($config),
            $config['queue'],
            $config['after_commit'] ?? false
        );
    }

    /**
     * Create new AMQP connection instance.
     *
     * @param  array  $config
     * @return \Interop\Amqp\AmqpContext
     */
    protected function createConnection(array $config): AmqpContext
    {
        $connection = $this->factory->connection($config['connection'] ?? null);
        $delayStrategy = $config['delay_strategy'] ?? RabbitMqDlxDelayStrategy::class;

        if ($connection instanceof DelayStrategyAware && is_a($delayStrategy, DelayStrategy::class, true)) {
            $connection->setDelayStrategy(is_string($delayStrategy) ? new $delayStrategy() : $delayStrategy);
        }

        return $connection;
    }
}
