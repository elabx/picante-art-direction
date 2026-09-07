<?php

namespace App\Engines\Data;

final readonly class JobObservation
{
    public function __construct(
        public string $normalizedStatus,
        public string $nativeStatus,
        public ?int $queuePosition,
        public ?array $result,
        public ?array $error,
    ) {}

    public function isTerminal(): bool
    {
        return in_array($this->normalizedStatus, ['completed', 'failed', 'cancelled'], true);
    }
}
