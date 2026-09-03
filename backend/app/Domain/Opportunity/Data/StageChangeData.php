<?php

namespace App\Domain\Opportunity\Data;

/**
 * Validated input for a stage transition. Keeping it a typed object rather than
 * an array means the Phase 3 AI tool layer can construct the same request the
 * HTTP layer does, with the same guarantees.
 */
final readonly class StageChangeData
{
    public function __construct(
        public int $toStageId,
        public ?string $note = null,
        public ?string $lossReason = null,
        public ?string $lossNote = null,
        public ?float $finalValue = null,
        public ?string $nextAction = null,
        public ?\DateTimeInterface $nextFollowUpAt = null,
    ) {}
}
