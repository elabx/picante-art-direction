<?php

namespace App\Models;

use App\Enums\FailureReason;
use App\Enums\GenerationKind;
use App\Enums\GenerationStatus;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'kind' => GenerationKind::class,
            'status' => GenerationStatus::class,
            'failure_reason' => FailureReason::class,
            'execution_snapshot' => 'array',
            'retryable' => 'boolean',
            'submission_started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
            'seen_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parentPiece(): BelongsTo
    {
        return $this->belongsTo(Piece::class, 'parent_piece_id');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(GenerationJob::class);
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(GenerationOutput::class);
    }

    public function pieces(): HasMany
    {
        return $this->hasMany(Piece::class);
    }

    public function inputUploads(): BelongsToMany
    {
        return $this->belongsToMany(InputUpload::class, 'generation_inputs');
    }

    public function restartedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'restarted_from_generation_id');
    }

    public function scopeNonTerminal(Builder $query): Builder
    {
        return $query->whereIn('status', GenerationStatus::nonTerminalValues());
    }
}
