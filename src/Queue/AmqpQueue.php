<?php

declare(strict_types=1);

namespace Jenky\Larabbit\Queue;

use Illuminate\Contracts\Queue\ClearableQueue;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue;
use Illuminate\Support\Str;
use Interop\Amqp\AmqpConsumer;
use Interop\Amqp\AmqpContext;
use Interop\Amqp\AmqpMessage;
use Interop\Amqp\AmqpQueue as InteropQueue;
use Interop\Amqp\AmqpTopic;
use Interop\Queue\Destination;

class AmqpQueue extends Queue implements QueueContract, ClearableQueue
{
    /**
     * The AMQP connection context instance.
     *
     * @var \Interop\Amqp\AmqpContext
     */
    protected $connection;

    /**
     * The name of the default queue.
     *
     * @var string
     */
    protected $default;

    /**
     * The current job instance.
     *
     * @var object
     */
    protected $currentJob;

    /**
     * Create new queue instance.
     *
     * @param  \Interop\Amqp\AmqpContext  $connection
     * @param  string  $default
     * @param  bool  $dispatchAfterCommit
     * @return void
     */
    public function __construct(
        AmqpContext $connection,
        string $default = 'default',
        bool $dispatchAfterCommit = false
    ) {
        $this->connection = $connection;
        $this->default = $default;
        $this->dispatchAfterCommit = $dispatchAfterCommit;
    }

    /**
     * Get the size of the queue.
     *
     * @param  string|null  $queue
     * @return int
     */
    public function size($queue = null)
    {
        return $this->declareQueue($queue);
    }

    /**
     * Push a new job onto the queue.
     *
     * @param  object|string  $job
     * @param  mixed  $data
     * @param  string|null  $queue
     * @return mixed
     */
    public function push($job, $data = '', $queue = null)
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data),
            $queue,
            null,
            function ($payload, $queue) {
                return $this->pushRaw($payload, $queue);
            }
        );
    }

    /**
     * Push a raw payload onto the queue.
     *
     * @param  string  $payload
     * @param  string|null  $queue
     * @param  array  $options
     * @return mixed
     */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $message = $this->createMessage($payload);

        $destination = $this->createDestination($queue);

        if ($destination instanceof InteropQueue) {
            $this->declareQueue($queue);
        }

        return $this->getConnection()->createProducer()->send(
            $destination, $message
        );
    }

    /**
     * Push a new job onto the queue after a delay.
     *
     * @param  \DateTimeInterface|\DateInterval|int  $delay
     * @param  object|string  $job
     * @param  mixed  $data
     * @param  string|null  $queue
     * @return mixed
     */
    public function later($delay, $job, $data = '', $queue = null)
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data),
            $queue,
            $delay,
            function ($payload, $queue, $delay) {
                return $this->laterRaw($delay, $payload, $queue);
            }
        );
    }

    /**
     * Push a raw job onto the queue after (n) seconds.
     *
     * @param  \DateTimeInterface|\DateInterval|int  $delay
     * @param  string  $payload
     * @param  string|null  $queue
     * @return mixed
     */
    protected function laterRaw($delay, $payload, $queue = null)
    {
        $message = $this->createMessage($payload);

        $destination = $this->createDestination($queue);

        if ($destination instanceof InteropQueue) {
            $this->declareQueue($queue);
        }

        return $this->getConnection()
            ->createProducer()
            ->setDeliveryDelay($this->secondsUntil($delay) * 1000)
            ->send($destination, $message);
    }

    /**
     * Pop the next job off of the queue.
     *
     * @param  string|null  $queue
     * @return \Illuminate\Contracts\Queue\Job|null
     */
    public function pop($queue = null)
    {
        $this->declareQueue($queue);

        $consumer = $this->getConnection()->createConsumer(
            $this->createQueue($queue)
        );

        if ($message = $consumer->receive(1000)) { // 1 sec
            return $this->convertMessageToJob($message, $consumer);
        }

        return null;
    }

    /**
     * Delete all of the jobs from the queue.
     *
     * @param  string  $queue
     * @return int
     */
    public function clear($queue)
    {
        return $this->getConnection()->purgeQueue($this->createQueue($queue));
    }

    public function convertMessageToJob(AmqpMessage $message, AmqpConsumer $consumer): JobContract
    {
        return new AmqpJob(
            $this->container,
            $this->getConnection(),
            $consumer,
            $message,
            $this->connectionName
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function createObjectPayload($job, $queue)
    {
        $this->currentJob = $job;

        return parent::createObjectPayload($job, $queue);
    }

    /* protected function getJobExchange($job): ?string
    {
        if (! method_exists($job, 'exchange') && ! isset($job->exchange)) {
            return null;
        }

        return $job->exchange ?? $job->exchange();
    } */

    /**
     * Get the queue or return the default.
     *
     * @param  string|null  $queue
     * @return string
     */
    public function getQueue($queue)
    {
        return $queue ?: $this->default;
    }

    /**
     * Get the AMQP connection context.
     *
     * @return \Interop\Amqp\AmqpContext
     */
    public function getConnection(): AmqpContext
    {
        return $this->connection;
    }

    /**
     * Create new AMQP message instance.
     *
     * @param  mixed  $body
     * @return \Interop\Amqp\AmqpMessage
     */
    public function createMessage($body): AmqpMessage
    {
        $message = tap($this->getConnection()->createMessage($body))
            ->setDeliveryMode(AmqpMessage::DELIVERY_MODE_PERSISTENT);

        if (is_object($this->currentJob) && method_exists($this->currentJob, 'configureAmqpMessage')) {
            $this->currentJob->configureAmqpMessage($message);
            $this->currentJob = null;
        }

        return $message;
    }

    /**
     * Create queue instance.
     *
     * @param  string|null  $queue
     * @return \Interop\Amqp\AmqpQueue
     */
    protected function createQueue(?string $queue = null): InteropQueue
    {
        return $this->getConnection()->createQueue(
            $this->getQueue($queue)
        );
    }

    /**
     * Create exchange/topic instance.
     *
     * @param  null|string  $exchange
     * @return \Interop\Amqp\AmqpTopic
     */
    protected function createExchange(?string $exchange = null): AmqpTopic
    {
        return $this->getConnection()->createTopic(
            $this->getQueue($exchange)
        );
    }

    /**
     * Create new AMQP queue or exchange/topic instance.
     *
     * @param  null|string  $destination
     * @return \Interop\Queue\Destination
     */
    protected function createDestination(?string $destination = null): Destination
    {
        $destination = $this->getQueue($destination);

        if (Str::startsWith($destination, 'exchange:')) {
            return $this->createExchange(str_replace('exchange:', '', $destination));
        }

        return $this->createQueue($destination);
    }

    /**
     * Declare queue.
     *
     * @param  null|string  $queue
     * @return int
     */
    protected function declareQueue(?string $queue = null)
    {
        return $this->getConnection()->declareQueue(
            tap($this->createQueue($queue))->addFlag(InteropQueue::FLAG_DURABLE)
        );
    }
}
