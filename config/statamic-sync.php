<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sync Token
    |--------------------------------------------------------------------------
    |
    | A shared secret used to authenticate sync requests. Set this in your
    | .env file. The same token must be configured on both the remote
    | (serving) and local (pulling) environments.
    |
    */

    'token' => env('STATAMIC_SYNC_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Remote URL
    |--------------------------------------------------------------------------
    |
    | The base URL of the remote environment to pull content from.
    | Only needed on the local/pulling side.
    |
    | Example: https://example.com
    |
    */

    'remote' => env('STATAMIC_SYNC_REMOTE', ''),

    /*
    |--------------------------------------------------------------------------
    | Paths
    |--------------------------------------------------------------------------
    |
    | The directories to sync. These are relative to the project root.
    |
    */

    'paths' => [
        'content' => 'content',
        'assets' => 'public/assets',
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Prefix
    |--------------------------------------------------------------------------
    |
    | The URL prefix for the sync API endpoint on the serving side.
    |
    */

    'route_prefix' => '_sync',

    /*
    |--------------------------------------------------------------------------
    | IP Whitelist
    |--------------------------------------------------------------------------
    |
    | Optional list of allowed IP addresses. Leave empty to allow any IP
    | (token auth still required).
    |
    */

    'allowed_ips' => [],

];
