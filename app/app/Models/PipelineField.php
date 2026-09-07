<?php

namespace App\Models;

use App\Enums\FieldRole;
use App\Enums\FieldVisibility;
use App\Enums\InputType;
use Database\Factories\PipelineFieldFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineField extends Model
{
    /** @use HasFactory<PipelineFieldFactory> */
    use HasFactory;

    protected $fillable = [
        'pipeline_id',
        'name',
        'source_schema',
        'input_type',
        'required',
        'label_override',
        'help_text',
        'visibility',
        'has_fixed_value',
        'fixed_value',
        'role',
        'stale',
        'needs_configuration',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'input_type' => InputType::class,
            'visibility' => FieldVisibility::class,
            'role' => FieldRole::class,
            'source_schema' => 'array',
            'fixed_value' => 'json',
            'has_fixed_value' => 'boolean',
            'required' => 'boolean',
            'stale' => 'boolean',
            'needs_configuration' => 'boolean',
        ];
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }
}
