<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Apps;

use Mata\Cli\Command\AbstractNodeDatasetCommand;
use Mata\Services\Cache\CacheType;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** The README of an application as Markdown, for context on what it does. Untrusted text. */
#[AsCommand(name: 'apps:readme', description: 'Show the README of an application')]
final class AppsReadmeCommand extends AbstractNodeDatasetCommand
{
    protected function requiresApp(): bool
    {
        return true;
    }

    protected function datasetName(InputInterface $input): string
    {
        return 'readme';
    }

    protected function fetch(ServerDataCollectorInterface $collector, bool $live, InputInterface $input): ?array
    {
        return $collector->fetchAppReadme(self::appArgument($input), self::cacheType($live, CacheType::Readme));
    }

    protected function extract(mixed $result, InputInterface $input): array
    {
        return ['readme' => is_string($result) ? $result : ''];
    }

    protected function writeHuman(OutputInterface $output, array $data): void
    {
        // Raw: Markdown is full of `<…>`, and the formatter would read it as styling.
        $output->writeln($data['readme'] === '' ? 'No README.' : (string) $data['readme'], OutputInterface::OUTPUT_RAW);
    }
}
