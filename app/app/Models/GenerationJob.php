<?php

namespace App\Models;

use Database\Factories\GenerationJobFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GenerationJob extends Model
{
    /** @use HasFactory<GenerationJobFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'error' => 'array',
            'last_polled_at' => 'datetime',
            'next_poll_at' => 'datetime',
        ];
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(GenerationOutput::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->normalized_status, ['completed', 'failed', 'cancelled'], true);
    }
}
