<?php

declare(strict_types=1);

it('has at least one provider API key to compare against', function (): void {
    $keys = array_filter([
        'ANTHROPIC_API_KEY' => getenv('ANTHROPIC_API_KEY'),
        'GEMINI_API_KEY' => getenv('GEMINI_API_KEY'),
        'OPENAI_API_KEY' => getenv('OPENAI_API_KEY'),
    ]);

    expect($keys)->not->toBeEmpty('The accuracy suite calls real provider APIs. Set ANTHROPIC_API_KEY, GEMINI_API_KEY or OPENAI_API_KEY.');
});
