<?php

namespace Jenky\Larabbit\Queue;

use Illuminate\Contracts\Queue\ClearableQueue;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use Throwable;

class RabbitMQQueue extends Queue implements QueueContract, ClearableQueue
{
    use Concerns\InteractsWithExchange,
        Concerns\InteractsWithQueue;

    /**
     * The RabbitMQ connection instance.
     *
     * @var \PhpAmqpLib\Connection\AbstractConnection
     */
    protected $connection;

    /**
     * The RabbitMQ channel instance.
     *
     * @var \PhpAmqpLib\Channel\AMQPChannel
     */
    protected $channel;

    /**
     * The name of the default queue.
     *
     * @var string
     */
    protected $default;

    /**
     * List of already declared exchanges.
     *
     * @var array
     */
    protected $exchanges = [];

    /**
     * List of already declared queues.
     *
     * @var array
     */
    protected $queues = [];

    /**
     * List of already bound queues to exchanges.
     *
     * @var array
     */
    protected $boundQueues = [];

    /**
     * @var array
     */
    protected $options;

    public function __construct(
        AbstractConnection $connection,
        string $default = 'default',
        bool $dispatchAfterCommit = false,
        array $options = [],
    ) {
        $this->connection = $connection;
        $this->channel = $connection->channel();
        $this->default = $default;
        $this->dispatchAfterCommit = $dispatchAfterCommit;
        $this->options = $options;
    }

    /**
     * Get the size of the queue.
     *
     * @param  string|null  $queue
     * @return int
     *
     * @throws \PhpAmqpLib\Exception\AMQPProtocolChannelException
     * @throws \PhpAmqpLib\Exception\AMQPOutOfBoundsException
     * @throws \PhpAmqpLib\Exception\AMQPRuntimeException
     * @throws \PhpAmqpLib\Exception\AMQPTimeoutException
     * @throws \PhpAmqpLib\Exception\AMQPConnectionClosedException
     */
    public function size($queue = null)
    {
        $queue = $this->getQueue($queue);

        if (! $this->isQueueExists($queue)) {
            return 0;
        }

        // create a temporary channel, so the main channel will not be closed on exception
        $channel = $this->temporaryChannel();
        [$_, $size] = $channel->queue_declare($queue, true);
        $channel->close();

        return $size;
    }

    /**
     * Push a new job onto the queue.
     *
     * @param  string|object  $job
     * @param  mixed  $data
     * @param  string|null  $queue
     * @return mixed
     *
     * @throws \Illuminate\Queue\InvalidPayloadException
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
     *
     * @throws \PhpAmqpLib\Exception\AMQPChannelClosedException
     * @throws \PhpAmqpLib\Exception\AMQPConnectionBlockedException
     * @throws \PhpAmqpLib\Exception\AMQPInvalidArgumentException
     * @throws \PhpAmqpLib\Exception\AMQPConnectionClosedException
     * @throws \PhpAmqpLib\Exception\AMQPOutOfRangeException
     * @throws \PhpAmqpLib\Exception\AMQPRuntimeException
     */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        [$destination, $exchange, $exchangeType, $attempts] = $this->publishProperties($queue, $options);

        $this->declareDestination($destination, $exchange, $exchangeType);

        [$message, $correlationId] = $this->createMessage($payload, $attempts);

        $this->getChannel()->basic_publish($message, $exchange, $destination, true);

        return $correlationId;
    }

    /**
     * Push a new job onto the queue after (n) seconds.
     *
     * @param  \DateTimeInterface|\DateInterval|int  $delay
     * @param  string|object  $job
     * @param  mixed  $data
     * @param  string|null  $queue
     * @return mixed
     *
     * @throws \Illuminate\Queue\InvalidPayloadException
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

    public function laterRaw($delay, $payload, $queue = null, $attempts = 0)
    {
        $ttl = $this->secondsUntil($delay) * 1000;

        // When no ttl just publish a new message to the exchange or queue
        if ($ttl <= 0) {
            return $this->pushRaw($payload, $queue, ['delay' => $delay, 'attempts' => $attempts]);
        }

        $destination = $this->getQueue($queue).'.delay.'.$ttl;

        $this->declareQueue($destination, true, false, $this->getDelayQueueArguments($this->getQueue($queue), $ttl));

        [$message, $correlationId] = $this->createMessage($payload, $attempts);

        // Publish directly on the delayQueue, no need to publish trough an exchange.
        $this->getChannel()->basic_publish($message, null, $destination, true);

        return $correlationId;
    }

    public function bulk($jobs, $data = '', $queue = null): void
    {
        foreach ((array) $jobs as $job) {
            $this->bulkRaw($this->createPayload($job, $queue, $data), $queue, ['job' => $job]);
        }

        $this->getChannel()->publish_batch();
    }

    /**
     * @param  string  $payload
     * @param  null  $queue
     * @param  array  $options
     * @return mixed
     *
     * @throws AMQPProtocolChannelException
     */
    public function bulkRaw(string $payload, $queue = null, array $options = [])
    {
        [$destination, $exchange, $exchangeType, $attempts] = $this->publishProperties($queue, $options);

        $this->declareDestination($destination, $exchange, $exchangeType);

        [$message, $correlationId] = $this->createMessage($payload, $attempts);

        $this->getChannel()->batch_basic_publish($message, $exchange, $destination);

        return $correlationId;
    }

    public function pop($queue = null)
    {
        try {
            $queue = $this->getQueue($queue);

            $job = $this->getJobClass();

            /** @var AMQPMessage|null $message */
            if ($message = $this->getChannel()->basic_get($queue)) {
                return $this->currentJob = new $job(
                    $this->container,
                    $this,
                    $message,
                    $this->connectionName,
                    $queue
                );
            }
        } catch (AMQPProtocolChannelException $exception) {
            // If there is not exchange or queue AMQP will throw exception with code 404
            // We need to catch it and return null
            if ($exception->amqp_reply_code === 404) {
                // Because of the channel exception the channel was closed and removed.
                // We have to open a new channel. Because else the worker(s) are stuck in a loop, without processing.
                $this->channel = $this->connection->channel();

                return null;
            }

            throw $exception;
        } catch (AMQPChannelClosedException|AMQPConnectionClosedException $exception) {
            // Queue::pop used by worker to receive new job
            // Thrown exception is checked by Illuminate\Database\DetectsLostConnections::causedByLostConnection
            // Is has to contain one of the several phrases in exception message in order to restart worker
            // Otherwise worker continues to work with broken connection
            throw new AMQPRuntimeException(
                'Lost connection: '.$exception->getMessage(),
                $exception->getCode(),
                $exception
            );
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
        // create a temporary channel, so the main channel will not be closed on exception
        $channel = $this->temporaryChannel();
        $channel->queue_purge($this->getQueue($queue));
        $channel->close();
    }

    /**
     * Gets a queue/destination, by default the queue option set on the connection.
     *
     * @param  string|null  $queue
     * @return string
     */
    public function getQueue($queue = null): string
    {
        return $queue ?: $this->default;
    }

    /**
     * Get RabbitMQ connection instance.
     *
     * @return \PhpAmqpLib\Connection\AbstractConnection
     */
    public function getConnection(): AbstractConnection
    {
        return $this->connection;
    }

    /**
     * Get RabbitMQ channel instance.
     *
     * @return \PhpAmqpLib\Channel\AMQPChannel
     */
    public function getChannel(): AMQPChannel
    {
        return $this->channel;
    }

    /**
     * Create a temporary channel, so the main channel will not be closed on exception.
     *
     * @return \PhpAmqpLib\Channel\AMQPChannel
     *
     * @throws \PhpAmqpLib\Exception\AMQPOutOfBoundsException
     * @throws \PhpAmqpLib\Exception\AMQPRuntimeException
     * @throws \PhpAmqpLib\Exception\AMQPTimeoutException
     * @throws \PhpAmqpLib\Exception\AMQPConnectionClosedException
     */
    protected function temporaryChannel(): AMQPChannel
    {
        return $this->getConnection()->channel();
    }

    /**
     * Acknowledge the message.
     *
     * @param  RabbitMQJob  $job
     * @return void
     */
    public function ack(RabbitMQJob $job): void
    {
        $this->getChannel()->basic_ack(
            $job->getRabbitMQMessage()->getDeliveryTag()
        );
    }

    /**
     * Reject the message.
     *
     * @param  RabbitMQJob  $job
     * @param  bool  $requeue
     * @return void
     */
    public function reject(RabbitMQJob $job, bool $requeue = false): void
    {
        $this->getChannel()->basic_reject(
            $job->getRabbitMQMessage()->getDeliveryTag(), $requeue
        );
    }

    /**
     * Close the connection to RabbitMQ.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function close(): void
    {
        if ($this->currentJob && ! $this->currentJob->isDeletedOrReleased()) {
            $this->reject($this->currentJob, true);
        }

        try {
            $this->connection->close();
        } catch (Throwable $exception) {
            // Ignore the exception
        }
    }

    /**
     * Declare the destination when necessary.
     *
     * @param  string  $destination
     * @param  string|null  $exchange
     * @param  string|null  $exchangeType
     * @return void
     *
     * @throws AMQPProtocolChannelException
     */
    protected function declareDestination(
        string $destination,
        ?string $exchange = null,
        string $exchangeType = AMQPExchangeType::DIRECT
    ): void {
        // When a exchange is provided and no exchange is present in RabbitMQ, create an exchange.
        if ($exchange && ! $this->isExchangeExists($exchange)) {
            $this->declareExchange($exchange, $exchangeType);
        }

        // When a exchange is provided, just return.
        if ($exchange) {
            return;
        }

        // When the queue already exists, just return.
        if ($this->isQueueExists($destination)) {
            return;
        }

        // Create a queue for amq.direct publishing.
        $this->declareQueue($destination, true, false, $this->getQueueArguments($destination));
    }

    /**
     * Determine all publish properties.
     *
     * @param $queue
     * @param  array  $options
     * @return array
     */
    protected function publishProperties($queue, array $options = []): array
    {
        $queue = $this->getQueue($queue);
        $attempts = $options['attempts'] ?? 0;

        $destination = $this->getRoutingKey($queue);
        $exchange = $this->getExchange($options['exchange'] ?? null);
        $exchangeType = $this->getExchangeType($options['exchange_type'] ?? null);

        return [$destination, $exchange, $exchangeType, $attempts];
    }
}
