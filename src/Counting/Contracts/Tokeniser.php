<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Counting\Contracts;

interface Tokeniser
{
    /**
     * The number of tokens in the text.
     */
    public function count(string $text): int;
}
