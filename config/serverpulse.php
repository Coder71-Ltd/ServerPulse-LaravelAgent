<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ServerPulse API Configuration
    |--------------------------------------------------------------------------
    |
    | These values identify your ServerPulse installation. The api_base is
    | the root URL of your ServerPulse API and api_key is your unique
    | agent authentication key.
    |
    */

    'api_base' => env('SERVERPULSE_API_BASE', 'https://api.serverpulse.io'),

    'api_key' => env('SERVERPULSE_API_KEY'),

];
