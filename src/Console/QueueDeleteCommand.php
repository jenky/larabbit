<?php

declare(strict_types=1);

namespace Jenky\Larabbit\Console;

use Illuminate\Console\Command;
use Interop\Amqp\AmqpQueue;
use Jenky\Larabbit\Contracts\ConnectionFactory;

class QueueDeleteCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'amqp:delete-queue
                            {name : The name of the queue to delete}
                            {--connection= : The name of the AMQP connection to work}
                            {--unused : Check if queue has no consumers}
                            {--empty : Check if queue is empty}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete a queue';

    public function handle(ConnectionFactory $factory)
    {
        $connection = $factory->connection($this->option('connection'));
        $queue = $connection->createQueue($this->argument('name'));

        if ($this->option('unused')) {
            $queue->addFlag(AmqpQueue::FLAG_IFUNUSED);
        }

        if ($this->option('empty')) {
            $queue->addFlag(AmqpQueue::FLAG_IFEMPTY);
        }

        $connection->deleteQueue($queue);

        $this->info('Queue deleted successfully.');
    }
}
