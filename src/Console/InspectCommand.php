<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroom\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\BindingResolutionException;
use Laravel\Ai\Contracts\Agent;
use Phoenix1331\AiHeadroom\Data\TokenCount;
use Phoenix1331\AiHeadroom\HeadroomManager;
use Phoenix1331\AiHeadroom\Resolving\RequestResolver;

class InspectCommand extends Command
{
    protected $signature = 'headroom:inspect
        {agent : The agent class, e.g. "App\Ai\Agents\SupportAgent"}
        {prompt : The prompt to count}
        {--provider= : Count against this provider instead of the agent\'s}
        {--model= : Count against this model instead of the agent\'s}
        {--json : Output the count as JSON}';

    protected $description = 'Show what an agent request would send: tokens per part, context usage and estimated cost';

    public function handle(HeadroomManager $headroom, RequestResolver $resolver): int
    {
        $agent = $this->agent($this->stringArgument('agent'));

        if ($agent === null) {
            return self::INVALID;
        }

        $provider = $this->option('provider');
        $model = $this->option('model');

        // Counted directly rather than through Headroom::for(), so inspecting fires no events.
        $count = $headroom->countStep($resolver->resolve(
            $agent,
            $this->stringArgument('prompt'),
            provider: is_string($provider) && $provider !== '' ? $provider : null,
            model: is_string($model) && $model !== '' ? $model : null,
        ), null, $agent);

        if ($this->option('json')) {
            $this->line((string) json_encode($count->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        if (! $this->option('json')) {
            $this->report($count);
        }

        return $count->fits() ? self::SUCCESS : self::FAILURE;
    }

    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : '';
    }

    private function agent(string $class): ?Agent
    {
        if (! class_exists($class)) {
            $this->components->error("Agent class [{$class}] does not exist.");

            return null;
        }

        if (! is_subclass_of($class, Agent::class)) {
            $this->components->error("[{$class}] is not a laravel/ai agent.");

            return null;
        }

        try {
            $agent = $this->laravel->make($class);
        } catch (BindingResolutionException) {
            $this->components->error("[{$class}] could not be built: it needs constructor arguments the container cannot provide. Bind it in a service provider, or count it in code with Headroom::for(new {$class}(...), \$prompt).");

            return null;
        }

        return $agent instanceof Agent ? $agent : null;
    }

    private function report(TokenCount $count): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Agent</>', (string) $count->agent());
        $this->components->twoColumnDetail('<fg=gray>Model</>', $count->model().($count->provider() === null ? '' : " ({$count->provider()})"));
        $this->components->twoColumnDetail('<fg=gray>Method</>', sprintf('%s (expected error %s)', ucfirst($count->method()->value), $this->percent($count->confidence() * 100)));
        $this->newLine();

        $total = max(1, $count->total());
        $rows = [];

        foreach ($count->breakdown() as $part => $tokens) {
            $rows[] = [ucfirst($part), number_format($tokens), $this->percent($tokens / $total * 100)];
        }

        $overhead = $count->total() - array_sum($count->breakdown());

        if ($overhead > 0) {
            $rows[] = ['Message overhead', number_format($overhead), $this->percent($overhead / $total * 100)];
        }

        $rows[] = ['<options=bold>Total</>', '<options=bold>'.number_format($count->total()).'</>', '100%'];

        $this->table(['Part', 'Tokens', 'Share'], $rows);

        $this->components->twoColumnDetail('<fg=gray>Context</>', sprintf(
            '%s of %s tokens (%s), %s remaining',
            number_format($count->total()),
            number_format($count->contextWindow()),
            $this->percent($count->percentUsed()),
            number_format($count->remaining()),
        ));

        $cost = $count->estimatedCost();
        $symbol = $cost->currency->symbol();

        $this->components->twoColumnDetail('<fg=gray>Estimated cost</>', sprintf(
            '%s%s input + up to %s%s output = up to %s%s %s',
            $symbol,
            $this->money($cost->input),
            $symbol,
            $this->money($cost->output),
            $symbol,
            $this->money($cost->total()),
            $cost->currency->value,
        ));

        $this->newLine();

        if ($count->fits()) {
            $this->components->info('The request fits the context window.');

            return;
        }

        $this->components->error(sprintf('The request is %s tokens over the context window.', number_format(-$count->remaining())));
    }

    private function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2), '0'), '.').'%';
    }

    private function money(float $value): string
    {
        return number_format($value, $value > 0 && $value < 0.01 ? 6 : 4);
    }
}
