<?php

namespace App\Enums;

enum PipelineKind: string
{
    case Generator = 'generator';
    case Editor = 'editor';
    case Upscaler = 'upscaler';
}
