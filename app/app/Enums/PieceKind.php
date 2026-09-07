<?php

namespace App\Enums;

enum PieceKind: string
{
    case Original = 'original';
    case Edit = 'edit';
    case Upscale = 'upscale';
}
