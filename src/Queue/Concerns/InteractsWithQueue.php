<?php

declare(strict_types=1);

namespace Jenky\Larabbit\Queue\Concerns;

use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Wire\AMQPTable;

trait InteractsWithQueue
{
    /**
     * Checks if the given queue already present/defined in RabbitMQ.
     * Returns false when when the queue is missing.
     *
     * @param  string|null  $name
     * @return bool
     *
     * @throws \PhpAmqpLib\Exception\AMQPProtocolChannelException
     */
    public function isQueueExists(string $name = null): bool
    {
        try {
            // create a temporary channel, so the main channel will not be closed on exception
            $channel = $this->temporaryChannel();
            $channel->queue_declare($this->getQueue($name), true);
            $channel->close();

            return true;
        } catch (AMQPProtocolChannelException $exception) {
            if ($exception->amqp_reply_code === 404) {
                return false;
            }

            throw $exception;
        }
    }

    /**
     * Checks if the queue was already declared.
     *
     * @param  string  $name
     * @return bool
     */
    public function isQueueDeclared(string $name): bool
    {
        return in_array($name, $this->queues, true);
    }

    /**
     * Declare a queue in rabbitMQ, when not already declared.
     *
     * @param  string  $name
     * @param  bool  $durable
     * @param  bool  $autoDelete
     * @param  array  $arguments
     * @return void
     */
    public function declareQueue(
        string $name,
        bool $durable = true,
        bool $autoDelete = false,
        array $arguments = []
    ): void {
        if ($this->isQueueDeclared($name)) {
            return;
        }

        $this->getChannel()->queue_declare(
            $name,
            false,
            $durable,
            false,
            $autoDelete,
            false,
            new AMQPTable($arguments)
        );
    }

    /**
     * Delete a queue from rabbitMQ, only when present in RabbitMQ.
     *
     * @param  string  $name
     * @param  bool  $if_unused
     * @param  bool  $if_empty
     * @return void
     *
     * @throws \PhpAmqpLib\Exception\AMQPProtocolChannelException
     */
    public function deleteQueue(string $name, bool $if_unused = false, bool $if_empty = false): void
    {
        if (! $this->isQueueExists($name)) {
            return;
        }

        $this->getChannel()->queue_delete($name, $if_unused, $if_empty);
    }

    /**
     * Bind a queue to an exchange.
     *
     * @param  string  $queue
     * @param  string  $exchange
     * @param  string  $routingKey
     * @return void
     */
    public function bindQueue(string $queue, string $exchange, string $routingKey = ''): void
    {
        if (in_array(
            implode('', compact('queue', 'exchange', 'routingKey')),
            $this->boundQueues,
            true
        )) {
            return;
        }

        $this->getChannel()->queue_bind($queue, $exchange, $routingKey);
    }
}
