<?php

namespace App\Models;

use App\Enums\PipelineKind;
use Database\Factories\PipelineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pipeline extends Model
{
    /** @use HasFactory<PipelineFactory> */
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'kind',
        'engine',
        'provider_ref',
        'label',
        'input_schema',
        'schema_fetched_at',
        'config_revision',
        'readiness_errors',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'kind' => PipelineKind::class,
            'input_schema' => 'array',
            'readiness_errors' => 'array',
            'schema_fetched_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
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

    public function isReady(): bool
    {
        return $this->is_active && empty($this->readiness_errors);
    }
}
