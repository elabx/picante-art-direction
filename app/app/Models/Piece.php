<?php

namespace App\Models;

use App\Enums\PieceKind;
use Database\Factories\PieceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class Piece extends Model
{
    /** @use HasFactory<PieceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'kind' => PieceKind::class,
            'is_4k' => 'boolean',
            'selected' => 'boolean',
        ];
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }

    public function output(): BelongsTo
    {
        return $this->belongsTo(GenerationOutput::class, 'generation_output_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_piece_id');
    }

    public function root(): BelongsTo
    {
        return $this->belongsTo(self::class, 'root_piece_id');
    }

    public function rootId(): int
    {
        return $this->root_piece_id ?? $this->id;
    }

    public function versionChain(): Collection
    {
        $rootId = $this->rootId();

        return static::query()
            ->where(fn ($query) => $query->whereKey($rootId)->orWhere('root_piece_id', $rootId))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }
}
