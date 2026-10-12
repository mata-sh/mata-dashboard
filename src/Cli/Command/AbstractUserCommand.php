<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use Mata\Cli\CliOutput;
use Mata\Config\EnvConfigLoader;
use Mata\Config\Validation\InstanceConfigValidator;
use Mata\Models\Auth\UserRepository;
use Mata\Services\Auth\UserAdministrator;
use Mata\Utils\PathResolver;
use Mata\Utils\UsersFilePathResolver;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
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
        private readonly PathResolver $paths,
        private readonly string $configPath,
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = (new EnvConfigLoader())->load(InstanceConfigValidator::ENV_FILES, $this->configPath);
            $this->paths->applyConfig($config);
            $usersFile = UsersFilePathResolver::resolve($this->paths);
        } catch (Throwable $exception) {
            return $this->fail($input, $output, CliOutput::withWebUserHint("Cannot load the config: {$exception->getMessage()}"));
        }

        $user = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;
        $this->operator = is_array($user) ? $user['name'] : (string) getenv('USER');
        $this->env = $config->environment->env;

        $security = $config->cliSecurity;
        if ($security->strictUserCheck && !in_array($this->operator, $security->allowedUsers, true)) {
            return $this->fail($input, $output, "User '{$this->operator}' is not in CLI_ALLOWED_USERS (" . implode(', ', $security->allowedUsers) . ')');
        }
        if ($this->writes() && $this->env === 'prod' && !$input->getOption('yes')) {
            if (!$input->isInteractive()) {
                return $this->fail($input, $output, 'Production user changes need --yes without a terminal.');
            }
            $answer = $this->askLine($input, $output, "Modifying PRODUCTION users. Type 'CONFIRM PRODUCTION' to continue: ");
            if ($answer !== 'CONFIRM PRODUCTION') {
                return $this->fail($input, $output, 'Cancelled.');
            }
        }

        $this->audit = new Logger('user-management-cli');
        $handler = new StreamHandler($this->paths->logsPath . '/user-management-audit.log', Logger::INFO);
        $handler->setFormatter(new LineFormatter("[%datetime%] %level_name%: %message% %context%\n", 'Y-m-d H:i:s', true, true));
        $this->audit->pushHandler($handler);
        $this->audit->info('User management command started', ['operator' => $this->operator, 'environment' => $this->env, 'command' => $this->getName(), 'hostname' => gethostname()]);

        try {
            $users = new UserAdministrator(UserRepository::openForAdministration($usersFile), $this->audit);

            return $this->handle($users, $input, $output);
        } catch (Throwable $exception) {
            $this->audit->error('User management command failed', ['operator' => $this->operator, 'command' => $this->getName(), 'error' => $exception->getMessage()]);

            return $this->fail($input, $output, $exception->getMessage());
        }
    }

    /**
     * Password from --password-stdin, --generate-password, or a hidden prompt; null when none is possible.
     *
     * @return null|array{0:string,1:bool} password and whether it was generated
     */
    protected function readPassword(InputInterface $input, OutputInterface $output): ?array
    {
        if ($input->getOption('generate-password')) {
            return [rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '='), true];
        }
        if ($input->getOption('password-stdin')) {
            $line = fgets(STDIN);

            return $line === false ? null : [rtrim($line, "\r\n"), false];
        }
        if (!$input->isInteractive()) {
            return null;
        }
        $helper = $this->getHelper('question');
        $question = (new Question('Password: '))->setHidden(true)->setHiddenFallback(false);
        $answer = $helper instanceof QuestionHelper ? $helper->ask($input, $output, $question) : null;

        return is_string($answer) ? [$answer, false] : null;
    }

    protected function addPasswordOptions(): void
    {
        $this
            ->addOption('password-stdin', null, InputOption::VALUE_NONE, 'Read the password from the first line of stdin')
            ->addOption('generate-password', null, InputOption::VALUE_NONE, 'Generate a password and print it once');
    }

    /** bcrypt ignores everything after 72 bytes; demo accounts publish their credentials by design. */
    protected function passwordPolicyError(string $password): ?string
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

        return CliOutput::EXIT_OK;
    }

    protected function fail(InputInterface $input, OutputInterface $output, string $message): int
    {
        if ($input->getOption('json')) {
            CliOutput::json($output, ['status' => 'error', 'error' => $message]);
        } else {
            $output->writeln("<error>{$message}</error>");
        }

        return CliOutput::EXIT_ERROR;
    }

    private function askLine(InputInterface $input, OutputInterface $output, string $prompt): string
    {
        $helper = $this->getHelper('question');
        $answer = $helper instanceof QuestionHelper ? $helper->ask($input, $output, new Question($prompt)) : null;

        return is_string($answer) ? trim($answer) : '';
    }
}
