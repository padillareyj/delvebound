<?php

return [

    'balance_file' => env('BALANCE_FILE', 'config/balance.json'),

    // The app timezone stays UTC (the database is UTC); this is only used to display times.
    'display_timezone' => env('DISPLAY_TIMEZONE', 'Asia/Bangkok'),

    'allow_signup' => filter_var(env('ALLOW_SIGNUP', true), FILTER_VALIDATE_BOOL),

    'login_max_failures' => (int) env('LOGIN_MAX_FAILURES', 5),

    'login_lock_minutes' => (int) env('LOGIN_LOCK_MINUTES', 15),

    'stale_run_minutes' => (int) env('STALE_RUN_MINUTES', 15),

    'log_retention_days' => (int) env('LOG_RETENTION_DAYS', 60),

];
