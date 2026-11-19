<?php

declare(strict_types=1);

return [
    /*
     |--------------------------------------------------------------------------
     | Debugbar Settings
     |--------------------------------------------------------------------------
     |
     | Debugbar is enabled when APP_DEBUG is true; DEBUGBAR_ENABLED overrides it. The provider's
     | webhooks and the rest of the API are left alone.
     |
     */
    'except' => [
        'api*',
    ],
];
