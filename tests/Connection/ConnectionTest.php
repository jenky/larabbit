<?php

declare(strict_types=1);

namespace Jenky\Larabbit\Tests\Connection;

use Enqueue\AmqpBunny\AmqpContext as AmqpBunnyContext;
use Enqueue\AmqpLib\AmqpContext as AmqpLibContext;
use Interop\Amqp\AmqpContext;
use Jenky\Larabbit\Contracts\ConnectionFactory;
use Jenky\Larabbit\Tests\TestCase;

class ConnectionTest extends TestCase
{
    public function test_default_connection(): void
    {
        $factory = $this->app->make(ConnectionFactory::class);

        $this->assertInstanceOf(AmqpContext::class, $factory->connection());
    }

    public function test_connection_using_amqp_lib(): void
    {
        $factory = $this->app->make(ConnectionFactory::class);

        $this->assertInstanceOf(AmqpLibContext::class, $factory->connection('amqp_lib'));
    }

    public function test_connection_using_amqp_bunny(): void
    {
        $factory = $this->app->make(ConnectionFactory::class);

        $this->assertInstanceOf(AmqpBunnyContext::class, $factory->connection('amqp_bunny'));
    }
}
