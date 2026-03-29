<?php

declare(strict_types=1);

namespace App\Models;

final class Pair
{
    public function __construct(
        public readonly string $symbol,
        public readonly string $interval
    ) {
    }
}

