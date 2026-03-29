<?php

declare(strict_types=1);

namespace App\Models;

final class Signal
{
    public function __construct(
        public readonly string $symbol,
        public readonly string $direction,
        public readonly int $confidence,
        public readonly float $price,
        public readonly array $meta = []
    ) {
    }
}

