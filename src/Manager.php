<?php

declare(strict_types=1);

namespace Jenky\Larabbit;

use Closure;
use Enqueue\AmqpBunny\AmqpConnectionFactory as BunnyConnectionFactory;
use Enqueue\AmqpLib\AmqpConnectionFactory as AmqpLibConnectionFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\ForwardsCalls;
use Interop\Amqp\AmqpConnectionFactory;
use Interop\Amqp\AmqpContext;
use InvalidArgumentException;
use Jenky\Larabbit\Contracts\ConnectionFactory;

class Manager implements ConnectionFactory
{
    use ForwardsCalls;

    /**
     * The application instance.
     *
     * @var \Illuminate\Contracts\Foundation\Application
     */
    protected $app;

    /**
     * The array of resolved connections.
     *
     * @var array
     */
    protected $connections = [];

    /**
     * Create a new connection manager instance.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return void
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function getDefaultDriver()
    {
        return $this->app['config']['amqp.default'] ?? '';
    }

    /**
     * Get the configuration.
     *
     * @param  string  $name
     * @return null|array
     */
    protected function configurationFor($name): ?array
    {
        return $this->app['config']["amqp.connections.{$name}"] ?? null;
    }

    /**
     * Create AMQP connection instance.
     *
     * @param  null|string  $connection
     * @return \Interop\Amqp\AmqpContext
     */
    public function connection(?string $connection = null): AmqpContext
    {
        $connection ??= $this->getDefaultDriver();

        return $this->connections[$connection] ??= $this->resolve($connection)->createContext();
    }

    /**
     * Resolve the given log instance by name.
     *
     * @param  string  $name
     * @param  array|null  $config
     * @return \Interop\Amqp\AmqpConnectionFactory
     *
     * @throws \InvalidArgumentException
     */
    protected function resolve($name, ?array $config = null): AmqpConnectionFactory
    {
        $config ??= $this->configurationFor($name);

        if (is_null($config)) {
            throw new InvalidArgumentException("Connection [{$name}] is not defined.");
        }

        if (isset($this->customCreators[$config['driver']])) {
            return $this->callCustomCreator($config);
        }

        $driverMethod = 'create'.Str::studly($config['driver']).'Driver';

        if (method_exists($this, $driverMethod)) {
            return $this->{$driverMethod}($config);
        }

        throw new InvalidArgumentException("Driver [{$config['driver']}] is not supported.");
    }

    /**
     * Call a custom driver creator.
     *
     * @param  array  $config
     * @return \Interop\Amqp\AmqpConnectionFactory
     */
    protected function callCustomCreator(array $config): AmqpConnectionFactory
    {
        return $this->customCreators[$config['driver']]($this->app, $config);
    }

    /**
     * Register a custom driver creator Closure.
     *
     * @param  string  $driver
     * @param  \Closure  $callback
     * @return $this
     */
    public function extend($driver, Closure $callback)
    {
        $this->customCreators[$driver] = $callback->bindTo($this, $this);

        return $this;
    }

    /**
     * Create new AMQP connection factory instance by using php-amqplib driver.
     *
     * @param  array  $config
     * @return \Interop\Amqp\AmqpConnectionFactory
     */
    protected function createAmqpLibDriver(array $config): AmqpConnectionFactory
    {
        return new AmqpLibConnectionFactory($config);
    }

    /**
     * Create new AMQP connection factory instance by using BunnyPHP driver.
     *
     * @param  array  $config
     * @return \Interop\Amqp\AmqpConnectionFactory
     */
    protected function createAmqpBunnyDriver(array $config): AmqpConnectionFactory
    {
        return new BunnyConnectionFactory($config);
    }

    /**
     * Dynamically call the default driver instance.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->forwardCallTo($this->connection(), $method, $parameters);
    }
}
