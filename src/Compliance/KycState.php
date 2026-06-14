<?php

declare(strict_types=1);

namespace App\Compliance;

/**
 * KYC session states.
 *
 * State transitions:
 *   DRAFT -> PENDING -> APPROVED
 *                    -> REJECTED
 *                    -> INFO_REQUIRED -> PENDING (resubmission)
 *   PENDING -> EXPIRED (time-based)
 *   Any terminal state is final.
 */
enum KycState: string
{
    case DRAFT         = 'draft';
    case PENDING       = 'pending';
    case INFO_REQUIRED = 'info_required';
    case APPROVED      = 'approved';
    case REJECTED      = 'rejected';
    case EXPIRED       = 'expired';

    /**
     * Returns the states that this state can transition to.
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::DRAFT         => [self::PENDING],
            self::PENDING       => [self::APPROVED, self::REJECTED, self::INFO_REQUIRED, self::EXPIRED],
            self::INFO_REQUIRED => [self::PENDING],
            self::APPROVED      => [],  // terminal
            self::REJECTED      => [],  // terminal
            self::EXPIRED       => [],  // terminal
        };
    }

    public function isTerminal(): bool
    {
        return empty($this->allowedTransitions());
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
