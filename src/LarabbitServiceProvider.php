<?php

declare(strict_types=1);

namespace Jenky\Larabbit;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;
use Jenky\Larabbit\Connectors\AmqpConnector;
use Jenky\Larabbit\Contracts\ConnectionFactory;

class LarabbitServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/queue.php', 'queue.connections.rabbitmq');
        $this->mergeConfigFrom(__DIR__.'/../config/amqp.php', 'amqp');

        $this->registerConnection();
        // $this->registerWorker();

        /* if ($this->app->runningInConsole()) {
            $this->app->singleton(Console\ConsumeCommand::class, function ($app) {
                return new Console\ConsumeCommand(
                    $app['amqp.worker'], $app['cache.store']
                );
            });
        } */
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPublishing();

        $this->registerQueueConnector();

        $this->registerCommands();
    }

    /**
     * Register the package's publishable resources.
     *
     * @return void
     */
    protected function registerPublishing(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/amqp.php' => config_path('amqp.php'),
            ], 'amqp-config');
        }
    }

    /**
     * Register the package's commands.
     *
     * @return void
     */
    protected function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            Console\BindCommand::class,
            Console\UnbindCommand::class,
            Console\ConsumeCommand::class,
            Console\ExchangeDeclareCommand::class,
            Console\ExchangeDeleteCommand::class,
            Console\QueueDeclareCommand::class,
            Console\QueueDeleteCommand::class,
        ]);
    }

    /**
     * Register the package connection factory.
     *
     * @return void
     */
    protected function registerConnection(): void
    {
        $this->app->singleton(ConnectionFactory::class, function ($app) {
            return new Manager($app);
        });

        $this->app->alias(ConnectionFactory::class, 'amqp');

        $this->app->singleton('amqp.connection', function ($app) {
            return $app['amqp']->connection();
        });
    }

    protected function registerWorker(): void
    {
        $this->app->singleton('amqp.worker', function ($app) {
            $isDownForMaintenance = function () {
                return $this->app->isDownForMaintenance();
            };

            $resetScope = function () use ($app) {
                if (method_exists($app['log']->driver(), 'withoutContext')) {
                    $app['log']->withoutContext();
                }

                if (method_exists($app['db'], 'getConnections')) {
                    foreach ($app['db']->getConnections() as $connection) {
                        $connection->resetTotalQueryDuration();
                        $connection->allowQueryDurationHandlersToRunAgain();
                    }
                }

                $app->forgetScopedInstances();

                return Facade::clearResolvedInstances();
            };

            return new Worker(
                $app['queue'],
                $app['events'],
                $app[ExceptionHandler::class],
                $isDownForMaintenance,
                $resetScope
            );
        });
    }

    /**
     * Register package queue connector to queue manager.
     *
     * @return void
     */
    protected function registerQueueConnector(): void
    {
        $this->app['queue']->addConnector('amqp', function () {
            return new AmqpConnector(
                $this->app->make(ConnectionFactory::class)
            );
        });
    }
}
