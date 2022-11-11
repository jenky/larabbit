<?php

declare(strict_types=1);

namespace Jenky\Larabbit\Queue;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;
use Interop\Amqp\AmqpConsumer;
use Interop\Amqp\AmqpContext;
use Interop\Amqp\AmqpMessage;
use Interop\Queue\Exception\DeliveryDelayNotSupportedException;

class AmqpJob extends Job implements JobContract
{
    /**
     * The AMQP connection context instance.
     *
     * @var \Interop\Amqp\AmqpContext
     */
    protected $connection;

    /**
     * The AMQP consumer instance.
     *
     * @var \Interop\Amqp\AmqpConsumer
     */
    protected $consumer;

    /**
     * The AMQP message instance.
     *
     * @var \Interop\Amqp\AmqpMessage
     */
    protected $message;

    public function __construct(
        Container $container,
        AmqpContext $connection,
        AmqpConsumer $consumer,
        AmqpMessage $message,
        $connectionName
    ) {
        $this->container = $container;
        $this->connection = $connection;
        $this->consumer = $consumer;
        $this->message = $message;
        $this->connectionName = $connectionName;
        $this->queue = $consumer->getQueue()->getQueueName();
    }

    /**
     * Get the job identifier.
     *
     * @return string
     */
    public function getJobId()
    {
        return $this->message->getMessageId();
    }

    /**
     * Get the raw body of the job.
     *
     * @return string
     */
    public function getRawBody()
    {
        return $this->message->getBody();
    }

    /**
     * {@inheritdoc}
     */
    public function delete()
    {
        parent::delete();

        $this->consumer->acknowledge($this->message);
    }

    /**
     * {@inheritdoc}
     */
    public function release($delay = 0)
    {
        parent::release($delay);

        $requeueMessage = clone $this->message;
        $requeueMessage->setProperty('x-attempts', $this->attempts() + 1);

        $producer = $this->connection->createProducer();

        try {
            $producer->setDeliveryDelay($this->secondsUntil($delay) * 1000);
        } catch (DeliveryDelayNotSupportedException $e) {
        }

        $this->consumer->acknowledge($this->message);
        $producer->send($this->consumer->getQueue(), $requeueMessage);
    }

    /**
     * Get the message attempts.
     *
     * @return int
     */
    public function attempts(): int
    {
        return (int) $this->message->getProperty('x-attempts', 1);
    }
}
