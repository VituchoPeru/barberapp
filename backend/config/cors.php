<?php

declare(strict_types=1);

use App\Core\Env;

$origins = array_filter(array_map('trim', explode(',', (string) Env::get('CORS_ALLOWED_ORIGINS', ''))));

return [
    'allowed_origins' => array_values($origins),
    'allow_credentials' => true,
];
