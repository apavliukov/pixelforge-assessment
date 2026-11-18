<?php

declare(strict_types=1);

use App\Jobs\RefreshPricingJob;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new RefreshPricingJob)->everyFiveMinutes();
