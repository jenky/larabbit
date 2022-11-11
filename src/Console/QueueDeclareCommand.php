<?php

declare(strict_types=1);

namespace Jenky\Larabbit\Console;

use Illuminate\Console\Command;
use Interop\Amqp\AmqpQueue;
use Jenky\Larabbit\Contracts\ConnectionFactory;

class QueueDeclareCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'amqp:declare-queue
                            {name : The name of the queue to declare}
                            {--connection= : The name of the AMQP connection to work}
                            {--max-priority}
                            {--durable}
                            {--auto-delete}
                            {--quorum}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Declare queue';

    public function handle(ConnectionFactory $factory)
    {
        $connection = $factory->connection($this->option('connection'));
        $queue = $connection->createQueue($this->argument('name'));

        $arguments = array_filter([
            'x-max-priority' => (int) $this->option('max-priority'),
            'x-queue-type' => $this->option('quorum') ? 'quorum' : null,
        ]);

        if (! empty($arguments)) {
            $queue->setArguments($arguments);
        }

        if ($this->option('durable') || $this->confirm('Queue is durable?', true)) {
            $queue->addFlag(AmqpQueue::FLAG_DURABLE);
        }

        if ($this->option('auto-delete')) {
            $queue->addFlag(AmqpQueue::FLAG_AUTODELETE);
        }

        $connection->declareQueue($queue);

        $this->info('Queue declared successfully.');
    }
}
