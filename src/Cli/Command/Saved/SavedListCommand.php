<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Saved;

use DateTimeImmutable;
use Mata\Cli\CliConfig;
use Mata\Cli\CliInput;
use Mata\Cli\CliOutput;
use Mata\Cli\Provenance;
use Mata\Database\PdoDatabase;
use Mata\Models\SavedResult\SavedResultRepository;
use Mata\Services\Status\StatusLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Results that read-only commands stored with --save, without their documents; saved show prints one. */
#[AsCommand(name: 'saved:list', description: 'List results saved with --save, newest first')]
final class SavedListCommand extends Command
{
    public function __construct(private readonly CliConfig $cli)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('label', null, InputOption::VALUE_REQUIRED, 'Only results saved with this label')
            ->addOption('command', null, InputOption::VALUE_REQUIRED, 'Only results of this command, e.g. logs:show')
            ->addOption('server', null, InputOption::VALUE_REQUIRED, 'Only results about this server')
            ->addOption('app', null, InputOption::VALUE_REQUIRED, 'Only results about this application')
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Only results whose label, note or document contains this text')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only results saved this recently: ' . CliInput::DURATIONS)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of results', '50')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = CliInput::limit($input);
        $now = new DateTimeImmutable();
        $since = CliInput::since($input, $now);
        $filters = [
            'label' => CliInput::stringOption($input, 'label'),
            'command' => CliInput::stringOption($input, 'command'),
            'server' => CliInput::stringOption($input, 'server'),
            'app' => CliInput::stringOption($input, 'app'),
            'search' => CliInput::stringOption($input, 'search'),
            'since' => $since?->format(DATE_ATOM),
        ];

        $repository = new SavedResultRepository(new PdoDatabase($this->cli->load()->database));
        // One more row than asked tells whether the list is complete.
        $rows = $repository->search(
            $filters['label'],
            $filters['command'],
            $filters['server'],
            $filters['app'],
            $filters['search'],
            $since,
            $limit + 1,
        );
        $saved = array_slice($rows, 0, $limit);
        $data = Provenance::fromDatabase('cli_saved_results', $now) + [
            // An object even when empty, so `filters` keeps one JSON type.
            'filters' => (object) array_filter($filters, static fn (?string $value): bool => $value !== null),
            'truncated' => count($rows) > $limit,
            'saved' => $saved,
        ];

        if ($input->getOption('json')) {
            CliOutput::json($output, ['status' => StatusLevel::Ok->label()] + $data);
        } else {
            $this->writeHuman($output, $saved, $data['truncated']);
        }

        return StatusLevel::Ok->value;
    }

    /** @param list<array<string,mixed>> $saved */
    private function writeHuman(OutputInterface $output, array $saved, bool $truncated): void
    {
        if ($saved === []) {
            $output->writeln('No saved results.');

            return;
        }

        foreach ($saved as $result) {
            $output->writeln(self::line($result));
            if ($result['note'] !== null) {
                $output->writeln('    ' . CliOutput::value($result['note']));
            }
        }
        if ($truncated) {
            CliOutput::truncated($output, 'results', count($saved));
        }
    }

    /**
     * "#17 2026-10-26 14:03:12 incident-42: logs:show (web-01/shop) warning"; saved show shares it.
     *
     * @param array<string,mixed> $result a SavedResultRepository row
     */
    public static function line(array $result): string
    {
        return sprintf(
            '<info>#%d</info> %s %s: %s (%s) %s',
            $result['id'],
            CliOutput::value($result['created_at']),
            CliOutput::value($result['label']),
            CliOutput::value($result['command']),
            CliOutput::location($result['server'], $result['app']),
            CliOutput::value($result['status']),
        );
    }
}
