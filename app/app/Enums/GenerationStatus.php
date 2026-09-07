<?php

namespace App\Enums;

enum GenerationStatus: string
{
    case Pending = 'pending';
    case Submitting = 'submitting';
    case Submitted = 'submitted';
    case Processing = 'processing';
    case Downloading = 'downloading';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed], true);
    }

    /** @return list<string> */
    public static function nonTerminalValues(): array
    {
        return array_map(
            fn (self $status): string => $status->value,
            array_filter(self::cases(), fn (self $status): bool => ! $status->isTerminal()),
        );
    }
}
