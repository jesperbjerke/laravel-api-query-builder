<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Holds mapping between laravel locales and db collation to use
    |--------------------------------------------------------------------------
    |
    | Used mainly for setting proper COLLATE statement in orderByRaw queries
    |
    */
    'collations' => [
        'locale' => [
            'sv' => 'utf8mb4_swedish_ci',
            'sv-SE' => 'utf8mb4_swedish_ci'
        ]
    ],

];
