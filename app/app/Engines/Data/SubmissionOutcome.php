<?php

namespace App\Engines\Data;

final readonly class SubmissionOutcome
{
    /**
     * @param  list<string>  $jobIds
     */
    private function __construct(
        public string $state,
        public array $jobIds = [],
        public ?string $error = null,
        public ?int $httpStatus = null,
    ) {}

    /**
     * @param  list<string>  $jobIds
     */
    public static function accepted(array $jobIds): self
    {
        return new self('accepted', $jobIds);
    }

    public static function rejected(string $error, ?int $status = null): self
    {
        return new self('rejected', [], $error, $status);
    }

    public static function unknown(string $error): self
    {
        return new self('unknown', [], $error);
    }

    public function isAccepted(): bool
    {
        return $this->state === 'accepted';
    }

    public function isRejected(): bool
    {
        return $this->state === 'rejected';
    }

    public function isUnknown(): bool
    {
        return $this->state === 'unknown';
    }
}
