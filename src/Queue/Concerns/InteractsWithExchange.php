<?php

declare(strict_types=1);

namespace Jenky\Larabbit\Queue\Concerns;

use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Wire\AMQPTable;

trait InteractsWithExchange
{
    /**
     * Checks if the given exchange already present/defined in RabbitMQ.
     * Returns false when when the exchange is missing.
     *
     * @param  string  $exchange
     * @return bool
     *
     * @throws \PhpAmqpLib\Exception\AMQPProtocolChannelException
     */
    public function isExchangeExists(string $exchange): bool
    {
        if ($this->isExchangeDeclared($exchange)) {
            return true;
        }

        try {
            // create a temporary channel, so the main channel will not be closed on exception
            $channel = $this->temporaryChannel();
            $channel->exchange_declare($exchange, '', true);
            $channel->close();

            $this->exchanges[] = $exchange;

            return true;
        } catch (AMQPProtocolChannelException $exception) {
            if ($exception->amqp_reply_code === 404) {
                return false;
            }

            throw $exception;
        }
    }

    /**
     * Declare a exchange in rabbitMQ, when not already declared.
     *
     * @param  string  $name
     * @param  string  $type
     * @param  bool  $durable
     * @param  bool  $autoDelete
     * @param  array  $arguments
     * @return void
     */
    public function declareExchange(
        string $name,
        string $type = AMQPExchangeType::DIRECT,
        bool $durable = true,
        bool $autoDelete = false,
        array $arguments = []
    ): void {
        if ($this->isExchangeDeclared($name)) {
            return;
        }

        $this->getChannel()->exchange_declare(
            $name,
            $type,
            false,
            $durable,
            $autoDelete,
            false,
            true,
            new AMQPTable($arguments)
        );
    }

    /**
     * Delete a exchange from rabbitMQ, only when present in RabbitMQ.
     *
     * @param  string  $name
     * @param  bool  $unused
     * @return void
     *
     * @throws AMQPProtocolChannelException
     */
    public function deleteExchange(string $name, bool $unused = false): void
    {
        if (! $this->isExchangeExists($name)) {
            return;
        }

        $idx = array_search($name, $this->exchanges);
        unset($this->exchanges[$idx]);

        $this->getChannel()->exchange_delete(
            $name, $unused
        );
    }
}
