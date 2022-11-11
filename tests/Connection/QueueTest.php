<?php

declare(strict_types=1);

namespace Jenky\Larabbit\Tests\Connection;

use Jenky\Larabbit\Queue\AmqpQueue;
use Jenky\Larabbit\Tests\TestCase;

class QueueTest extends TestCase
{
    public function test_queue_connector(): void
    {
        $queue = $this->app['queue']->connection('rabbitmq');

        $this->assertInstanceOf(AmqpQueue::class, $queue);
    }
}
