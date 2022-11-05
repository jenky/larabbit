<?php

namespace Jenky\Larabbit;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Jenky\Larabbit\Connectors\RabbitMQConnector;

class LarabbitServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rabbitmq.php', 'queue.connections.rabbitmq');

        $this->registerConnection();
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->app['queue']->addConnector('rabbitmq', function () {
            return new RabbitMQConnector(
                $this->app->make(Dispatcher::class),
                $this->app->make('rabbitmq.resolver')
            );
        });
    }

    protected function registerConnection(): void
    {
        $config = $this->app->make('config')->get('queue.connections.rabbitmq', []);

        $this->app->singleton(
            'rabbitmq.resolver', $resolver = $this->connectionResolver($config['connection_resolver'] ?? null)
        );

        $this->app->bind('rabbitmq.connection', function () use ($config, $resolver) {
            return $resolver($config['connection'] ?? []);
        });
    }

    /**
     * Get the connection resolver.
     *
     * @param  \Closure|string|null  $resolver
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    protected function connectionResolver($resolver = null)
    {
        $resolver = $resolver ?: ConnectionResolver::class;

        if (is_callable($resolver)) {
            return $resolver;
        }

        if (! class_exists($resolver)) {
            throw new InvalidArgumentException('Invalid connection resolver.');
        }

        return $this->app->make($resolver);
    }
}
