<?php

namespace Jenky\Larabbit\Contracts;

use Interop\Amqp\AmqpContext;

interface ConnectionFactory
{
    /**
     * Create AMQP connection instance.
     *
     * @param  null|string  $connection
     * @return \Interop\Amqp\AmqpContext
     */
    public function connection(?string $connection = null): AmqpContext;
}
