<?php

namespace App\Models;

use Database\Factories\InputUploadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InputUpload extends Model
{
    /** @use HasFactory<InputUploadFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'finalized_at' => 'datetime',
            'cleanup_marked_at' => 'datetime',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
