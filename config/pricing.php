<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Price multiplier
    |--------------------------------------------------------------------------
    |
    | The provider cost is multiplied by this to get the user charge. It is in
    | basis points (1 = 0.01%, 10000 = 100%): 12500 charges 125% of the provider
    | cost, a 25% markup. Each generation keeps the value
    | it was charged with, so changing it does not rewrite history.
    |
    */

    'price_multiplier_basis_points' => 12500,

];
