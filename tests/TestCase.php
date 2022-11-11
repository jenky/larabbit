<?php

namespace Jenky\Larabbit\Tests;

use Jenky\Larabbit\LarabbitServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    /**
     * Get package providers.
     *
     * @param  \Illuminate\Foundation\Application  $app
     *
     * @return array
     */
    protected function getPackageProviders($app)
    {
        return [
            LarabbitServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application   $app
     *
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        $config = $app->get('config');

        $config->set('database.default', 'testbench');

        $config->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $config->set('app.debug', true);

        $connection = [
            'host' => env('RABBITMQ_HOST', '127.0.0.1'),
            'port' => env('RABBITMQ_PORT', 5672),
            'user' => env('RABBITMQ_USER', 'guest'),
            'password' => env('RABBITMQ_PASSWORD', 'guest'),
            'vhost' => env('RABBITMQ_VHOST', '/'),
        ];

        $config->set('amqp.connections.amqp_bunny', array_merge([
            'driver' => 'amqp-bunny',
        ], $connection));

        $config->set('amqp.connections.amqp_lib', array_merge([
            'driver' => 'amqp-lib',
        ], $connection));
    }
}
