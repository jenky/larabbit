<?php

declare(strict_types=1);

namespace Jenky\Larabbit\Console;

use Illuminate\Console\Command;
use Interop\Amqp\AmqpTopic;
use Jenky\Larabbit\Contracts\ConnectionFactory;

class ExchangeDeclareCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'amqp:declare-exchange
                            {name : The name of the exchange/topic to declare}
                            {--connection= : The name of the AMQP connection to work}
                            {--type=}
                            {--durable}
                            {--auto-delete}
                            {--internal}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Declare exchange/topic';

    public function handle(ConnectionFactory $factory)
    {
        $connection = $factory->connection($this->option('connection'));
        $topic = $connection->createTopic($this->argument('name'));
        $topic->setType($this->type());

        if ($this->option('durable') || $this->confirm('Exchange is durable?', true)) {
            $topic->addFlag(AmqpTopic::FLAG_DURABLE);
        }

        if ($this->option('auto-delete')) {
            $topic->addFlag(AmqpTopic::FLAG_AUTODELETE);
        }

        if ($this->option('internal')) {
            $topic->addFlag(AmqpTopic::FLAG_INTERNAL);
        }

        $connection->declareTopic($topic);

        $this->info('Exchange declared successfully.');
    }

    /**
     * Get the exchange type.
     *
     * @return string
     */
    protected function type(): string
    {
        return $this->option('type') ?: $this->choice('Please select exchange type', [
            AmqpTopic::TYPE_DIRECT,
            AmqpTopic::TYPE_FANOUT,
            AmqpTopic::TYPE_TOPIC,
            AmqpTopic::TYPE_HEADERS,
        ], AmqpTopic::TYPE_DIRECT);
    }
}
