<?php

namespace App\Enums;

enum FieldRole: string
{
    case Prompt = 'prompt';
    case Image = 'image';
    case None = 'none';
}
