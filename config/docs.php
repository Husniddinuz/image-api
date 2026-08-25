<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Interactive API documentation
    |--------------------------------------------------------------------------
    |
    | Swagger UI rendered from openapi.yaml, the same file that ships in the
    | repository -- there is no second copy of the contract to drift. Disable
    | it in production if the API surface is not meant to be public.
    |
    */

    'enabled' => (bool) env('API_DOCS_ENABLED', true),

    'path' => env('API_DOCS_PATH', 'docs'),

    'spec' => base_path('openapi.yaml'),

    /*
    | Pinned so the docs page renders identically for everyone. Bump
    | deliberately rather than floating on a major tag.
    */
    'swagger_ui_version' => '5.32.14',

];
