<?php

namespace App\Enums;

enum InputType: string
{
    case String = 'string';
    case Image = 'image';
    case Integer = 'integer';
    case Number = 'number';
    case Boolean = 'boolean';
    case Unknown = 'unknown';
}
