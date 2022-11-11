<?php

declare(strict_types=1);

namespace Jenky\Larabbit\Console;

use Illuminate\Console\Command;
use Interop\Amqp\Impl\AmqpBind;
use Jenky\Larabbit\Contracts\ConnectionFactory;
use Throwable;

class BindCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'amqp:bind
                            {queue : The queue name}
                            {exchange : The exchange name}
                            {--connection= : The name of the AMQP connection to work}
                            {--routing-key= : The binding routing key}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Bind queue to exchange';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle(ConnectionFactory $factory)
    {
        $connection = $factory->connection($this->option('connection'));

        try {
            $connection->bind(new AmqpBind(
                $connection->createQueue($this->argument('queue')),
                $connection->createTopic($this->argument('exchange')),
                $this->option('routing-key')
            ));
        } catch (Throwable $e) {
            return $this->error($e->getMessage());
        }

        $this->info('Bind queue to exchange successfully.');
    }
}
