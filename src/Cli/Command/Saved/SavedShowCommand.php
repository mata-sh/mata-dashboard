<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Saved;

use Mata\Cli\CliConfig;
use Mata\Cli\CliInput;
use Mata\Cli\CliOutput;
use Mata\Database\PdoDatabase;
use Mata\Models\SavedResult\SavedResultRepository;
use Mata\Services\Status\StatusLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One saved result with the JSON document the command printed then. The document keeps its own
 * `status` and `observed_at`; the `status` of this command only says it was found.
 */
#[AsCommand(name: 'saved:show', description: 'Show one result saved with --save, with its document')]
final class SavedShowCommand extends Command
{
    public function __construct(private readonly CliConfig $cli)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Saved result ID, see saved list')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = CliInput::id((string) $input->getArgument('id'), 'The saved result ID');

        $result = (new SavedResultRepository(new PdoDatabase($this->cli->load()->database)))->find($id);
        if ($result === null) {
            return CliOutput::fail($input, $output, "Saved result {$id} does not exist.");
        }
        $document = $result['document'];
        unset($result['document']);

        if ($input->getOption('json')) {
            CliOutput::json($output, ['status' => StatusLevel::Ok->label(), 'saved' => $result, 'document' => $document]);
        } else {
            $output->writeln(SavedListCommand::line($result));
            if ($result['note'] !== null) {
                $output->writeln(CliOutput::value($result['note']));
            }
            // Raw: the document is data, and `<…>` in it must not be read as styling.
            $output->writeln(
                (string) json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                OutputInterface::OUTPUT_RAW,
            );
        }

        return StatusLevel::Ok->value;
    }
}
