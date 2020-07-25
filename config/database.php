<?php
    return [
        'default' => 'mongodb',
        'connections' => [
            'mongodb' => [
                'driver' => 'mongodb',
                'dsn'=> env('DB_DSN'),
                'host' => env('DB_HOST', 'localhost'),
                'port' => env('DB_PORT', 27017),
                'username' => env('DB_USERNAME'),
                'password' => env('DB_PASSWORD'),
                'database' => env('DB_DATABASE'),
                'options' => [
                    'database' => 'admin' // sets the authentication database required by mongo 3
                ]
            ],
        ],
        'migrations' => 'migrations',
    ];
