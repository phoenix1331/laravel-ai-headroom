<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Counting;

use Phoenix1331\AiHeadroom\Counting\Contracts\Tokeniser;
use Yethee\Tiktoken\Encoder;
use Yethee\Tiktoken\EncoderProvider;

/**
 * OpenAI's o200k_base encoding. The vocabulary is downloaded on first use and
 * cached in $cachePath; the encoder is loaded once per process.
 */
class TiktokenTokeniser implements Tokeniser
{
    private ?Encoder $encoder = null;

    /**
     * @param  non-empty-string  $cachePath
     * @param  non-empty-string  $encoding
     */
    public function __construct(
        private readonly string $cachePath,
        private readonly string $encoding = 'o200k_base',
    ) {}

    public function count(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        return count($this->encoder()->encode($text));
    }

    private function encoder(): Encoder
    {
        if ($this->encoder !== null) {
            return $this->encoder;
        }

        if (! is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
        }

        $provider = new EncoderProvider;
        $provider->setVocabCache($this->cachePath);

        return $this->encoder = $provider->get($this->encoding);
    }
}
