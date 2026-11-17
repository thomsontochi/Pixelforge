<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Generation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'reference_images' => 'array',
            'rates' => 'array',
            'usage' => 'array',
            'estimated_cost_pico' => 'integer',
            'max_cost_pico' => 'integer',
            'provider_cost_pico' => 'integer',
            'charged_pico' => 'integer',
            'image_url_expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CreditLedgerEntry::class);
    }

    /**
     * Shape sent to the Vue pages. Money goes out as formatted strings plus exact values.
     *
     * @return array<string, mixed>
     */
    public function toPresentation(): array
    {
        $imageExpired = $this->image_url_expires_at !== null && $this->image_url_expires_at->isPast();

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'is_final' => $this->status->isFinal(),
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images,
            'estimated_cost' => Money::format($this->estimated_cost_pico),
            'max_cost' => Money::format($this->max_cost_pico),
            'charged' => $this->charged_pico === null ? null : Money::format($this->charged_pico),
            'charged_exact' => $this->charged_pico === null ? null : Money::toUsdString($this->charged_pico),
            'usage' => $this->usage,
            'image_url' => $imageExpired ? null : $this->image_url,
            'image_expired' => $imageExpired,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'notice' => $this->notice,
            'receipt' => $this->receipt(),
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }

    /**
     * Itemised bill for a finished generation: tokens x rate per line, Pixelforge's cost,
     * our markup, and what the user paid, next to what we estimated and held.
     *
     * @return array<string, mixed>|null
     */
    public function receipt(): ?array
    {
        if ($this->usage === null || $this->rates === null || $this->provider_cost_pico === null) {
            return null;
        }

        $labels = [
            'input_text_tokens' => 'Prompt text',
            'input_image_tokens' => 'Reference images',
            'output_tokens' => 'Image output',
            'reasoning_tokens' => 'Reasoning',
        ];

        $lines = [];

        foreach ($labels as $type => $label) {
            $tokens = (int) ($this->usage[$type] ?? 0);
            $perToken = intdiv(Money::fromUsdString($this->rates[$type] ?? '0'), 1_000_000);

            $lines[] = [
                'label' => $label,
                'tokens' => $tokens,
                'rate' => '$'.$this->rates[$type].' / 1M',
                'cost' => Money::format($tokens * $perToken),
            ];
        }

        $charged = (int) $this->charged_pico;
        $absorbed = $this->status === GenerationStatus::Failed && $this->provider_cost_pico > 0;

        return [
            'lines' => $lines,
            'provider_cost' => Money::format($this->provider_cost_pico),
            'markup' => Money::format($absorbed ? 0 : $charged - $this->provider_cost_pico),
            'charged' => Money::format($charged),
            'estimated' => Money::format($this->estimated_cost_pico),
            'held' => Money::format($this->max_cost_pico),
            'returned' => Money::format($this->max_cost_pico - $charged),
            'billed_after_we_gave_up' => $absorbed,
        ];
    }
}
