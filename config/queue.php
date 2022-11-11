<?php

return [

    'driver' => 'amqp',
    'queue' => env('RABBITMQ_QUEUE', 'default'),
    'worker' => env('RABBITMQ_WORKER', 'default'),

];
