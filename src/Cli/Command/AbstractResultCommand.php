<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use Closure;
use InvalidArgumentException;
use Mata\Cli\CliConfig;
use Mata\Cli\CliInput;
use Mata\Cli\CliOutput;
use Mata\Database\PdoDatabase;
use Mata\Models\SavedResult\SavedResultRepository;
use Mata\Services\Status\StatusLevel;
use PDOException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A read-only command whose result is one JSON document. --json prints it; --save stores it in
 * the database under a --label and --note, so an agent finds it again with saved list. --save
 * does not change the human output beyond a closing "Saved as" line.
 */
abstract class AbstractResultCommand extends Command
{
    /** The output options, left out of the arguments a saved result records. */
    private const OUTPUT_OPTIONS = ['json', 'save', 'label', 'note'];

    private const MAX_LABEL = 128;

    private const MAX_NOTE = 1000;

    /** The size of the MEDIUMTEXT `document` column. */
    private const MAX_DOCUMENT_BYTES = 16_777_215;

    public function __construct(protected readonly CliConfig $cli)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print as JSON')
            ->addOption('save', null, InputOption::VALUE_NONE, 'Store the JSON document in the database; find it with saved list')
            ->addOption('label', null, InputOption::VALUE_REQUIRED, 'With --save: a label to find it by, e.g. incident-42; generated without it')
            ->addOption('note', null, InputOption::VALUE_REQUIRED, 'With --save: why it was saved');
    }

    /** Rejects bad --label and --note before the command reads anything. */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $label = CliInput::stringOption($input, 'label');
        $note = CliInput::stringOption($input, 'note');
        if (!$input->getOption('save') && ($label !== null || $note !== null)) {
            throw new InvalidArgumentException('--label and --note need --save.');
        }
        if ($label !== null && mb_strlen($label) > self::MAX_LABEL) {
            throw new InvalidArgumentException('--label must be at most ' . self::MAX_LABEL . ' characters.');
        }
        if ($note !== null && mb_strlen($note) > self::MAX_NOTE) {
            throw new InvalidArgumentException('--note must be at most ' . self::MAX_NOTE . ' characters.');
        }
    }

    /**
     * Prints the document: as JSON with --json, else through $writeHuman. With --save it is
     * stored first, and the JSON gains a `saved` block with its id and label.
     *
     * @param array<string,mixed> $fields     the document fields after `status`
     * @param Closure(): void     $writeHuman
     */
    protected function report(InputInterface $input, OutputInterface $output, StatusLevel $level, array $fields, Closure $writeHuman): int
    {
        $document = ['status' => $level->label()] + $fields;
        $saved = $input->getOption('save') ? $this->save($input, $document) : null;

        if ($input->getOption('json')) {
            CliOutput::json($output, $document + ($saved === null ? [] : ['saved' => $saved]));
        } else {
            $writeHuman();
            if ($saved !== null) {
                $output->writeln(sprintf('<comment>Saved as #%d, label %s.</comment>', $saved['id'], CliOutput::value($saved['label'])));
            }
        }

        return $level->value;
    }

    /**
     * @param array<string,mixed> $document
     *
     * @return array{id:int,label:string}
     */
    private function save(InputInterface $input, array $document): array
    {
        $server = self::nameFromInputOrDocument($input, $document, 'server');
        $app = self::nameFromInputOrDocument($input, $document, 'app');
        $label = CliInput::stringOption($input, 'label') ?? $this->generatedLabel($server, $app);
        $json = json_encode(
            ['schema_version' => CliOutput::SCHEMA_VERSION] + $document,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        if (strlen($json) > self::MAX_DOCUMENT_BYTES) {
            throw new RuntimeException('The result is too large to save; lower --limit.');
        }

        try {
            $repository = new SavedResultRepository(new PdoDatabase($this->cli->load()->database));
            $id = $repository->save(
                command: (string) $this->getName(),
                arguments: $this->rerunArguments($input),
                server: $server,
                app: $app,
                label: $label,
                note: CliInput::stringOption($input, 'note'),
                status: $document['status'],
                document: $json,
            );
        } catch (PDOException $exception) {
            throw new RuntimeException("Not saved: {$exception->getMessage()} Check bin/mata migrate status.", 0, $exception);
        }

        return ['id' => $id, 'label' => $label];
    }

    /**
     * The arguments and the options that differ from their default, so the run can be repeated.
     *
     * @return array<string,mixed>
     */
    private function rerunArguments(InputInterface $input): array
    {
        $definition = $this->getNativeDefinition();
        $arguments = [];
        foreach ($definition->getArguments() as $name => $argument) {
            $value = $input->getArgument($name);
            if ($value !== null) {
                $arguments[$name] = $value;
            }
        }
        foreach ($definition->getOptions() as $name => $option) {
            $value = $input->getOption($name);
            if (!in_array($name, self::OUTPUT_OPTIONS, true) && $value !== $option->getDefault()) {
                $arguments["--{$name}"] = $value;
            }
        }

        return $arguments;
    }

    /**
     * A server or app name: the command's argument or option of that name, else the document
     * field, e.g. the server of the event that evidence looked at.
     *
     * @param array<string,mixed> $document
     */
    private static function nameFromInputOrDocument(InputInterface $input, array $document, string $name): ?string
    {
        $value = match (true) {
            $input->hasArgument($name) => $input->getArgument($name),
            $input->hasOption($name) => $input->getOption($name),
            default => null,
        };
        $value ??= $document[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** e.g. logs-show-web01-shop-20261026-140312 */
    private function generatedLabel(?string $server, ?string $app): string
    {
        $parts = [str_replace(':', '-', (string) $this->getName()), $server, $app, date('Ymd-His')];

        return mb_substr(implode('-', array_filter($parts, static fn (?string $part): bool => $part !== null)), 0, self::MAX_LABEL);
    }
}
