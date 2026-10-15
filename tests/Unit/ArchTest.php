<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

arch('debugging functions are not left in the code')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();

arch('every file declares strict types')
    ->expect('Phoenix1331\AiHeadroom')
    ->toUseStrictTypes();

arch('value objects are final and readonly')
    ->expect('Phoenix1331\AiHeadroom\Data')
    ->toBeFinal()
    ->toBeReadonly();

it('keeps interfaces inside Contracts namespaces', function (): void {
    $misplaced = collect(Finder::create()->files()->in(__DIR__.'/../../src')->name('*.php'))
        ->filter(fn (SplFileInfo $file): bool => preg_match('/^\s*interface\s+\w+/m', $file->getContents()) === 1)
        ->reject(fn (SplFileInfo $file): bool => str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Contracts'.DIRECTORY_SEPARATOR))
        ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($misplaced)->toBeEmpty();
});
