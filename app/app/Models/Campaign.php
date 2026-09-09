<?php

namespace App\Models;

use App\Enums\PipelineKind;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Campaign extends Model
{
    /** @use HasFactory<CampaignFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'brand_id',
        'name',
        'slug',
        'description',
        'cover_path',
        'starts_on',
        'ends_on',
        'default_pipeline_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function pipelines(): BelongsToMany
    {
        return $this->belongsToMany(Pipeline::class)
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderBy('campaign_pipeline.sort_order')
            ->orderBy('pipelines.id');
    }

    public function generations(): HasMany
    {
        return $this->hasMany(Generation::class);
    }

    public function pieces(): HasMany
    {
        return $this->hasMany(Piece::class);
    }

    public function defaultPipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class, 'default_pipeline_id');
    }

    public function activeGenerators(): BelongsToMany
    {
        return $this->pipelines()
            ->where('pipelines.kind', PipelineKind::Generator)
            ->where('pipelines.is_ready', true);
    }

    public function activeEditor(): ?Pipeline
    {
        return $this->pipelines()
            ->where('pipelines.kind', PipelineKind::Editor)
            ->where('pipelines.is_ready', true)
            ->first();
    }

    public function activeUpscaler(): ?Pipeline
    {
        return $this->pipelines()
            ->where('pipelines.kind', PipelineKind::Upscaler)
            ->where('pipelines.is_ready', true)
            ->first();
    }
}
