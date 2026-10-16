<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Tests\Accuracy\Support;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\PendingStep;

/**
 * The accuracy corpus: the kinds of content estimates drift most on.
 */
final class Corpus
{
    /**
     * @return array<string, PendingStep> Sample name => step for the given provider and model.
     */
    public static function steps(string $provider, string $model): array
    {
        $text = fn (string $file): string => trim((string) file_get_contents(__DIR__.'/../Corpus/'.$file));

        $steps = [];

        foreach (['prose' => 'prose.txt', 'code' => 'code.txt', 'json' => 'json.txt', 'french' => 'french.txt', 'japanese' => 'japanese.txt'] as $name => $file) {
            $steps[$name] = new PendingStep(0, true, $provider, $model, null, [new UserMessage($text($file))], [], null, null);
        }

        $steps['tool-heavy'] = new PendingStep(0, true, $provider, $model, 'You are a support agent for an online shop. Use the tools to answer accurately.', [new UserMessage('Has order AB-48213 shipped, and can I change the delivery address?')], self::tools(), null, null);

        return $steps;
    }

    /**
     * @return list<CorpusTool>
     */
    private static function tools(): array
    {
        return [
            new CorpusTool('lookup_order', 'Look up an order by its reference and return its status, items and totals.', fn (JsonSchema $s): array => [
                'reference' => $s->string()->description('The order reference, e.g. AB-48213')->required(),
            ]),
            new CorpusTool('track_parcel', 'Get the latest tracking events for a parcel from the carrier.', fn (JsonSchema $s): array => [
                'carrier' => $s->string()->enum(['parcelco', 'royal_mail', 'dpd'])->required(),
                'tracking_number' => $s->string()->required(),
            ]),
            new CorpusTool('update_address', 'Change the delivery address of an order that has not yet shipped.', fn (JsonSchema $s): array => [
                'reference' => $s->string()->required(),
                'line_1' => $s->string()->required(),
                'line_2' => $s->string(),
                'city' => $s->string()->required(),
                'postcode' => $s->string()->required(),
            ]),
            new CorpusTool('issue_refund', 'Refund all or part of an order to the original payment method.', fn (JsonSchema $s): array => [
                'reference' => $s->string()->required(),
                'amount' => $s->number()->description('Amount in the order currency')->required(),
                'reason' => $s->string()->enum(['lost', 'damaged', 'late', 'other'])->required(),
            ]),
            new CorpusTool('search_help_centre', 'Search help centre articles and return the best matches.', fn (JsonSchema $s): array => [
                'query' => $s->string()->required(),
                'limit' => $s->integer()->min(1)->max(10),
            ]),
            new CorpusTool('escalate', 'Hand the conversation to a human agent with a short summary.', fn (JsonSchema $s): array => [
                'summary' => $s->string()->required(),
                'priority' => $s->string()->enum(['low', 'normal', 'urgent'])->required(),
            ]),
        ];
    }
}
