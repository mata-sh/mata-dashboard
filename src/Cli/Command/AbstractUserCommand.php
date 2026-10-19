<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use Mata\Cli\CliConfig;
use Mata\Cli\CliOutput;
use Mata\Models\Auth\UserRepository;
use Mata\Services\Auth\UserAdministrator;
use Mata\Services\Status\StatusLevel;
use Mata\Utils\UsersFilePathResolver;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Throwable;

/**
 * Shared guards of the user commands, kept from scripts/user-management/manage-users.php:
 * CLI_STRICT_USER_CHECK / CLI_ALLOWED_USERS, an audit log in logs/user-management-audit.log,
 * and a typed confirmation before production writes. Without a terminal, --yes replaces it.
 */
abstract class AbstractUserCommand extends Command
{
    protected LoggerInterface $audit;
    protected string $operator = 'unknown';
    protected string $env = 'dev';

    public function __construct(
        private readonly CliConfig $cli,
    ) {
        parent::__construct();
    }

    abstract protected function writes(): bool;

    abstract protected function handle(UserAdministrator $users, InputInterface $input, OutputInterface $output): int;

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print as JSON');
        if ($this->writes()) {
            $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip the production confirmation (required in prod without a terminal)');
        }
    }

    /**
     * Prompts for missing arguments, so `bin/mata user create` works without any. Optional
     * arguments are only asked for once a required one was, and keep their default on Enter.
     */
    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        $prompted = false;
        foreach ($this->getDefinition()->getArguments() as $argument) {
            $name = $argument->getName();
            if ($argument->isRequired() && $input->getArgument($name) === null) {
                $input->setArgument($name, $this->askLine($input, $output, ucfirst($name) . ': '));
                $prompted = true;
            } elseif ($prompted && !$argument->isRequired()) {
                $default = is_string($argument->getDefault()) ? $argument->getDefault() : '';
                $answer = $this->askLine($input, $output, ucfirst($name) . " [{$default}]: ");
                $input->setArgument($name, $answer !== '' ? $answer : $default);
            }
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = $this->cli->load();
            $usersFile = UsersFilePathResolver::resolve($this->cli->paths);
        } catch (Throwable $exception) {
            return CliOutput::fail($input, $output, CliOutput::withWebUserHint("Cannot load the config: {$exception->getMessage()}"));
        }

        $this->operator = CliOutput::currentUser() ?? (string) getenv('USER');
        $this->env = $config->environment->env;

        $security = $config->cliSecurity;
        if ($security->strictUserCheck && !in_array($this->operator, $security->allowedUsers, true)) {
            return CliOutput::fail($input, $output, "User '{$this->operator}' is not in CLI_ALLOWED_USERS (" . implode(', ', $security->allowedUsers) . ')');
        }
        if ($this->writes() && $this->env === 'prod') {
            $refused = CliOutput::confirmProduction($input, $output, 'PRODUCTION users');
            if ($refused !== null) {
                return $refused;
            }
        }

        $this->audit = new Logger('user-management-cli');
        $handler = new StreamHandler($this->cli->paths->logsPath . '/user-management-audit.log', Logger::INFO);
        $handler->setFormatter(new LineFormatter("[%datetime%] %level_name%: %message% %context%\n", 'Y-m-d H:i:s', true, true));
        $this->audit->pushHandler($handler);
        $this->audit->pushProcessor($this->addAuditContext(...));
        $this->audit->info('User management command started', ['hostname' => gethostname()]);

        try {
            $users = new UserAdministrator(UserRepository::openForAdministration($usersFile), $this->audit);

            return $this->handle($users, $input, $output);
        } catch (Throwable $exception) {
            $this->audit->error('User management command failed', ['error' => $exception->getMessage()]);

            return CliOutput::fail($input, $output, $exception->getMessage());
        }
    }

    /**
     * Password from --generate-password, --password-stdin, or a hidden prompt, checked against
     * the policy. Throws when there is none or it is rejected; execute() reports that.
     *
     * @return array{0:string,1:bool} password and whether it was generated
     */
    protected function newPassword(InputInterface $input, OutputInterface $output): array
    {
        if ($input->getOption('generate-password')) {
            return [self::generatePassword(), true];
        }
        $password = null;
        if ($input->getOption('password-stdin')) {
            $line = fgets(STDIN);
            $password = $line === false ? null : rtrim($line, "\r\n");
        } elseif ($input->isInteractive()) {
            $answer = (new QuestionHelper())->ask($input, $output, (new Question('Password (empty to generate): '))->setHidden(true)->setHiddenFallback(false));
            if (!is_string($answer) || $answer === '') {
                return [self::generatePassword(), true];
            }
            $password = $answer;
        }
        if ($password === null) {
            throw new RuntimeException('No password: pass --password-stdin or --generate-password without a terminal.');
        }
        $policyError = $this->passwordPolicyError($password);
        if ($policyError !== null) {
            throw new RuntimeException($policyError);
        }

        return [$password, false];
    }

    private static function generatePassword(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }

    protected function addPasswordOptions(): void
    {
        $this
            ->addOption('password-stdin', null, InputOption::VALUE_NONE, 'Read the password from the first line of stdin')
            ->addOption('generate-password', null, InputOption::VALUE_NONE, 'Generate a password and print it once');
    }

    /** bcrypt ignores everything after 72 bytes; demo accounts publish their credentials by design. */
    private function passwordPolicyError(string $password): ?string
    {
        if ($this->env === 'demo') {
            return null;
        }
        if (mb_strlen($password) < 12) {
            return 'Password must be at least 12 characters.';
        }
        if (strlen($password) > 72) {
            return 'Password must be at most 72 bytes; bcrypt ignores the rest.';
        }

        return null;
    }

    /** @param array<string,mixed> $payload */
    protected function succeed(InputInterface $input, OutputInterface $output, string $message, array $payload = []): int
    {
        if ($input->getOption('json')) {
            CliOutput::json($output, ['status' => 'ok'] + $payload);
        } else {
            $output->writeln("<info>{$message}</info>");
        }

        return StatusLevel::Ok->value;
    }

    /** Every audit entry records who ran which command against which environment. */
    private function addAuditContext(LogRecord $record): LogRecord
    {
        $context = $record->context + [
            'operator' => $this->operator,
            'environment' => $this->env,
            'command' => $this->getName(),
        ];

        return $record->with(context: $context);
    }

    private function askLine(InputInterface $input, OutputInterface $output, string $prompt): string
    {
        $helper = $this->getHelper('question');
        $answer = $helper instanceof QuestionHelper ? $helper->ask($input, $output, new Question($prompt)) : null;

        return is_string($answer) ? trim($answer) : '';
    }
}
