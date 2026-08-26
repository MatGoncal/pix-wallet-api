<?php

namespace App\Enums;

enum PaymentStatusEnum: string
{
    case Pending = 'PENDING';
    case Paid = 'PAID';
    case Expired = 'EXPIRED';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';
}
