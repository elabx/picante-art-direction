<?php

namespace App\Enums;

enum OutputStatus: string
{
    case Pending = 'pending';
    case Downloading = 'downloading';
    case Stored = 'stored';
    case Failed = 'failed';
}
