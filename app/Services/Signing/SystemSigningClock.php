<?php

declare(strict_types=1);

namespace App\Services\Signing;

use Illuminate\Support\Carbon;

/**
 * Default clock backed by Carbon::now(), so Carbon::setTestNow() still controls
 * it in the wider test suite while unit tests may inject a fixed clock.
 */
final class SystemSigningClock implements SigningClock
{
    public function timestamp(): int
    {
        return Carbon::now()->getTimestamp();
    }
}
