<?php

namespace App\Models;

use App\Enums\FailureReason;
use App\Enums\OutputStatus;
use Database\Factories\GenerationOutputFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GenerationOutput extends Model
{
    /** @use HasFactory<GenerationOutputFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => OutputStatus::class,
            'failure_reason' => FailureReason::class,
            'claim_version' => 'integer',
            'next_attempt_at' => 'datetime',
        ];
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(GenerationJob::class, 'generation_job_id');
    }

    public function piece(): HasOne
    {
        return $this->hasOne(Piece::class, 'generation_output_id');
    }
}
