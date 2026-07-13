<?php

declare(strict_types=1);

namespace App\Services\Signing;

use Illuminate\Contracts\Filesystem\Filesystem;

final readonly class ValidatedCertificateStorage
{
    public function __construct(
        public string $disk,
        public Filesystem $filesystem,
    ) {}
}
