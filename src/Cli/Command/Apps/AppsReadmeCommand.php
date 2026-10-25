<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Apps;

use Mata\Cli\Command\AbstractNodeEvidenceCommand;
use Mata\Services\Cache\CacheType;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** The README of an application as Markdown, for context on what it does. Untrusted text. */
#[AsCommand(name: 'apps:readme', description: 'Show the README of an application')]
final class AppsReadmeCommand extends AbstractNodeEvidenceCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('app', InputArgument::REQUIRED, 'Application name, see apps list');
    }

    protected function dataset(InputInterface $input): string
    {
        return 'readme';
    }

    protected function fetch(ServerDataCollectorInterface $collector, bool $live, InputInterface $input): ?array
    {
        return $collector->fetchAppReadme(self::app($input), self::cacheType($live, CacheType::Readme));
    }

    protected function extract(mixed $result, InputInterface $input): array
    {
        return ['app' => (string) $input->getArgument('app'), 'readme' => is_string($result) ? $result : ''];
    }

    protected function writeHuman(OutputInterface $output, array $data): void
    {
        $output->writeln($data['readme'] === '' ? 'No README.' : (string) $data['readme'], OutputInterface::OUTPUT_RAW);
    }
}
