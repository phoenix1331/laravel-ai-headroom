<?php

declare(strict_types=1);

use Phoenix1331\AiHeadroom\Counting\TiktokenTokeniser;

// Downloads the o200k vocabulary (~3.6 MB) on the first run, then reads it from build/tiktoken.
function tiktoken(): TiktokenTokeniser
{
    return new TiktokenTokeniser(dirname(__DIR__, 2).'/build/tiktoken');
}

it('counts tokens with the o200k encoding', function (string $text, int $tokens): void {
    expect(tiktoken()->count($text))->toBe($tokens);
})->with([
    'greeting' => ['Hello, world', 3],
    'sentence' => ['Where is my refund?', 5],
]);

it('returns zero for empty text without loading the vocabulary', function (): void {
    $path = sys_get_temp_dir().'/headroom-never-created-'.uniqid();

    expect((new TiktokenTokeniser($path))->count(''))->toBe(0)
        ->and(is_dir($path))->toBeFalse();
});

it('loads the vocabulary once and reuses it', function (): void {
    $tokeniser = tiktoken();

    expect($tokeniser->count('first call'))->toBe(2)
        ->and($tokeniser->count('second call'))->toBe(2);
});
