<?php

namespace App\Engines\Data;

final readonly class EngineSchema
{
    public function __construct(
        public string $name,
        public string $versionId,
        public ?array $inputSchema,
    ) {}
}
