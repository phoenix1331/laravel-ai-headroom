<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Tests\Fixtures;

use Phoenix1331\AiHeadroom\Counting\Contracts\Tokeniser;

/**
 * One token per whitespace-separated word, so expected counts are easy to read.
 */
class WordTokeniser implements Tokeniser
{
    public function count(string $text): int
    {
        return count(preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
