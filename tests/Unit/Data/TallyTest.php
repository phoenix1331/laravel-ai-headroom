<?php

declare(strict_types=1);

use Phoenix1331\AiHeadroom\Data\Tally;
use Phoenix1331\AiHeadroom\Enums\CountMethod;

it('rejects a negative total', function (): void {
    new Tally(-1, ['instructions' => 0, 'history' => 0, 'tools' => 0, 'attachments' => 0, 'prompt' => 0], CountMethod::Estimated, 0.1);
})->throws(InvalidArgumentException::class, 'cannot be negative');
