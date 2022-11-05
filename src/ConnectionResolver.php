<?php

namespace Jenky\Larabbit;

use Illuminate\Support\Str;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory;

class ConnectionResolver
{
    /**
     * Create new AMQP connection instance.
     *
     * @param  array  $options
     * @return \PhpAmqpLib\Connection\AbstractConnection
     */
    public function __invoke(array $options = []): AbstractConnection
    {
        return AMQPConnectionFactory::create(static::config($options));
    }

    /**
     * Create new AMQP config instance.
     *
     * @param  array  $options
     * @return \PhpAmqpLib\Connection\AMQPConnectionConfig
     */
    public static function config(array $options): AMQPConnectionConfig
    {
        $config = new AMQPConnectionConfig;

        foreach ($options as $key => $value) {
            $method = 'set'.Str::studly($key);

            if (method_exists($config, $key)) {
                $config->{$method}($value);
            }
        }

        return $config;
    }
}
