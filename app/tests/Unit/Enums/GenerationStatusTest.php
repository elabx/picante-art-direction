<?php

use App\Enums\GenerationStatus;

it('marks only completed and failed as terminal', function () {
    expect(GenerationStatus::Completed->isTerminal())->toBeTrue()
        ->and(GenerationStatus::Failed->isTerminal())->toBeTrue()
        ->and(GenerationStatus::Pending->isTerminal())->toBeFalse()
        ->and(GenerationStatus::Submitting->isTerminal())->toBeFalse()
        ->and(GenerationStatus::Downloading->isTerminal())->toBeFalse();
});

it('returns every non-terminal status value', function () {
    expect(GenerationStatus::nonTerminalValues())->toBe([
        'pending',
        'submitting',
        'submitted',
        'processing',
        'downloading',
    ]);
});
