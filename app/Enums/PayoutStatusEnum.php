<?php

namespace App\Enums;

enum PayoutStatusEnum: string
{
    case Queued = 'QUEUED';
    case Processing = 'PROCESSING';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
}
