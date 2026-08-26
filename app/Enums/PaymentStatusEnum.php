<?php

namespace App\Enums;

enum PaymentStatusEnum: string
{
    case Pending = 'PENDING';
    case Paid = 'PAID';
    case Expired = 'EXPIRED';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';

    /**
     * `PENDING` is the only open state. Once a charge settles, expires, fails or
     * is cancelled it is closed for good: a late or replayed provider event must
     * never be able to reopen it and move money.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Paid, self::Expired, self::Failed, self::Cancelled],
            self::Paid, self::Expired, self::Failed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }
}
