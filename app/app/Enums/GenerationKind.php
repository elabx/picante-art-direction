<?php

namespace App\Enums;

enum GenerationKind: string
{
    case Series = 'series';
    case Edit = 'edit';
    case Upscale = 'upscale';
}
