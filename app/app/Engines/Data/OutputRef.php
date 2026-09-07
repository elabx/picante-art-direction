<?php

namespace App\Engines\Data;

final readonly class OutputRef
{
    public function __construct(
        public int $index,
        public string $url,
    ) {}
}
