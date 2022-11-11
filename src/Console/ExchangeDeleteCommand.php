<?php

declare(strict_types=1);

namespace Jenky\Larabbit\Console;

use Illuminate\Console\Command;
use Interop\Amqp\AmqpTopic;
use Jenky\Larabbit\Contracts\ConnectionFactory;

class ExchangeDeleteCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'amqp:delete-exchange
                            {name : The name of the exchange/topic to delete}
                            {--connection= : The name of the AMQP connection to work}
                            {--unused : Check if exchange/topic is unused}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete an exchange/topic';

    public function handle(ConnectionFactory $factory)
    {
        $connection = $factory->connection($this->option('connection'));
        $topic = $connection->createTopic($this->argument('name'));

        if ($this->option('unused')) {
            $topic->addFlag(AmqpTopic::FLAG_IFUNUSED);
        }

        $connection->deleteTopic($topic);

        $this->info('Exchange deleted successfully.');
    }
}
