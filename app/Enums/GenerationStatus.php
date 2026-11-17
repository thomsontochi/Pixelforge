<?php

namespace App\Enums;

enum GenerationStatus: string
{
    // Ours: created locally, not yet accepted by Pixelforge (e.g. waiting out a rate limit).
    case Pending = 'pending';
    // Pixelforge's lifecycle: queued -> processing -> succeeded | failed.
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /**
     * Position in the lifecycle. A generation may only move forward, so a late or
     * duplicated "processing" event can never undo "succeeded" or "failed".
     */
    public function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function active(): array
    {
        return [self::Pending->value, self::Queued->value, self::Processing->value];
    }
}
