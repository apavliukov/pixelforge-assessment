<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Markup
    |--------------------------------------------------------------------------
    |
    | What users pay on top of the provider cost, in basis points of that cost:
    | 12500 charges 125% of it, a 25% markup. Each generation keeps the value
    | it was charged with, so changing it does not rewrite history.
    |
    */

    'markup_bps' => 12500,

];
