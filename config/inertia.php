<?php

return [

    'ensure_pages_exist' => false,

    'page_paths' => [resource_path('js/pages')],

    'page_extensions' => ['tsx'],

    'testing' => [
        'ensure_pages_exist' => true,
        'page_paths' => [resource_path('js/pages')],
        'page_extensions' => ['tsx'],
    ],

    'history' => [
        'encrypt' => (bool) env('INERTIA_ENCRYPT_HISTORY', false),
    ],

];
