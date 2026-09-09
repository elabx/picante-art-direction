<?php

namespace App\Models;

use App\Enums\PipelineKind;
use Database\Factories\PipelineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pipeline extends Model
{
    /** @use HasFactory<PipelineFactory> */
    use HasFactory;

    protected $fillable = [
        'kind',
        'engine',
        'provider_ref',
        'label',
        'input_schema',
        'schema_fetched_at',
        'config_revision',
        'readiness_errors',
        'is_ready',
    ];

    protected function casts(): array
    {
        return [
            'kind' => PipelineKind::class,
            'input_schema' => 'array',
            'readiness_errors' => 'array',
            'schema_fetched_at' => 'datetime',
            'is_ready' => 'boolean',
        ];
    }

    public function campaigns(): BelongsToMany
    {
        return $this->belongsToMany(Campaign::class)->withPivot('sort_order')->withTimestamps();
    }

    public function fields(): HasMany
    {
        return $this->hasMany(PipelineField::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function activeFields(): HasMany
    {
        return $this->fields()->where('stale', false);
    }

    public function inputUploads(): BelongsToMany
    {
        return $this->belongsToMany(InputUpload::class, 'pipeline_inputs');
    }

    public function isReady(): bool
    {
        return $this->is_ready;
    }
}
