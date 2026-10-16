<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Data;

use InvalidArgumentException;

/**
 * The estimated cost of a request: the input cost, plus the worst-case output
 * cost if the model used every output token it is allowed (`maxTokens`).
 *
 * Amounts are rounded to 8 decimal places, which keeps per-token prices on
 * the cheapest models while removing floating point noise from totals.
 */
final readonly class Cost
{
    private const int PRECISION = 8;

    public float $input;

    public float $output;

    public function __construct(
        float $input,
        float $output,
        public string $currency,
    ) {
        if ($input < 0 || $output < 0) {
            throw new InvalidArgumentException('Cost amounts cannot be negative.');
        }

        $this->input = round($input, self::PRECISION);
        $this->output = round($output, self::PRECISION);
    }

    /**
     * @param  float  $inputPricePerMillion  Price per million input tokens.
     * @param  float  $outputPricePerMillion  Price per million output tokens.
     */
    public static function forTokens(
        int $inputTokens,
        int $maxOutputTokens,
        float $inputPricePerMillion,
        float $outputPricePerMillion,
        string $currency,
    ): self {
        if ($inputTokens < 0 || $maxOutputTokens < 0) {
            throw new InvalidArgumentException('Token counts cannot be negative.');
        }

        return new self(
            input: $inputTokens * $inputPricePerMillion / 1_000_000,
            output: $maxOutputTokens * $outputPricePerMillion / 1_000_000,
            currency: $currency,
        );
    }

    /**
     * The worst case: input plus every allowed output token.
     */
    public function total(): float
    {
        return round($this->input + $this->output, self::PRECISION);
    }

    /**
     * @return array{input: float, output: float, total: float, currency: string}
     */
    public function toArray(): array
    {
        return [
            'input' => $this->input,
            'output' => $this->output,
            'total' => $this->total(),
            'currency' => $this->currency,
        ];
    }
}
