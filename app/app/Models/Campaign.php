<?php

namespace App\Models;

use App\Enums\PipelineKind;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public function pipelines(): HasMany
    {
        return $this->hasMany(Pipeline::class)->orderBy('sort_order');
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

    public function activeGenerators(): HasMany
    {
        return $this->pipelines()
            ->where('kind', PipelineKind::Generator)
            ->where('is_active', true);
    }

    public function activeEditor(): ?Pipeline
    {
        return $this->pipelines()
            ->where('kind', PipelineKind::Editor)
            ->where('is_active', true)
            ->first();
    }

    public function activeUpscaler(): ?Pipeline
    {
        return $this->pipelines()
            ->where('kind', PipelineKind::Upscaler)
            ->where('is_active', true)
            ->first();
    }
}
