<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use GuzzleHttp\Client;
use Mata\Cli\CliOutput;
use Mata\Config\Dto\ApplicationConfig;
use Mata\Config\EnvConfigLoader;
use Mata\Config\Monitoring\Loader\ServersConfigLoader;
use Mata\Config\Validation\ConfigValidationReport;
use Mata\Config\Validation\InstanceConfigValidator;
use Mata\Database\MigrationRunner;
use Mata\Database\PdoDatabase;
use Mata\Services\Cron\CronHeartbeatReader;
use Mata\Services\Cron\Dto\CronJobState;
use Mata\Utils\PathResolver;
use Mata\Utils\UsersFilePathResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Application-side installation checks, run as the web server user so permissions are judged
 * from its side. `make doctor` keeps the host-side checks (mode, Docker, .env) and calls this.
 */
#[AsCommand(name: 'doctor', description: 'Check the installation from the application\'s side')]
final class DoctorCommand extends Command
{
    /** Only the Docker image ships this script, and it runs cron in the web container itself. */
    private const DOCKER_CRON_SETUP = '/usr/local/bin/mata-cron-setup';

    /** @var list<array{check:string,level:string,message:string,hint:?string}> */
    private array $checks = [];

    public function __construct(
        private readonly PathResolver $paths,
        private readonly string $configPath,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print as JSON')
            ->setHelp('Exit codes: 0 all checks pass, 1 warnings, 2 a check failed. Run as the web server user.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->checks = [];
        $config = $this->checkConfig();
        if ($config !== null) {
            $this->checkDirectories();
            $this->checkUsersFile();
            $this->checkSecretsFile();
            $this->checkDatabase($config);
            $this->checkOpa($config);
            $this->checkCron();
        }

        $exitCode = CliOutput::EXIT_OK;
        foreach ($this->checks as $check) {
            $exitCode = max($exitCode, $check['level'] === 'error' ? CliOutput::EXIT_ERROR : ($check['level'] === 'warning' ? CliOutput::EXIT_WARNING : CliOutput::EXIT_OK));
        }

        if ($input->getOption('json')) {
            CliOutput::json($output, ['status' => CliOutput::statusName($exitCode), 'checks' => $this->checks]);

            return $exitCode;
        }
        foreach ($this->checks as $check) {
            $mark = match ($check['level']) {
                'ok' => '<info>✓</info>',
                'warning' => '<comment>!</comment>',
                default => '<error>✗</error>',
            };
            $output->writeln("  {$mark} {$check['check']}: {$check['message']}");
            if ($check['hint'] !== null) {
                $output->writeln("      <comment>{$check['hint']}</comment>");
            }
        }

        return $exitCode;
    }

    private function checkConfig(): ?ApplicationConfig
    {
        $report = (new InstanceConfigValidator())->validate($this->configPath);
        $level = match ($report->exitCode()) {
            ConfigValidationReport::EXIT_ERROR => 'error',
            ConfigValidationReport::EXIT_WARNING => 'warning',
            default => 'ok',
        };
        $this->add('config', $level, count($report->problems) . ' problem(s) in ' . $report->configPath, $level === 'ok' ? null : 'bin/mata config validate');

        try {
            $config = (new EnvConfigLoader())->load(InstanceConfigValidator::ENV_FILES, $this->configPath);
            $this->paths->applyConfig($config);
        } catch (Throwable $exception) {
            $this->add('environment', 'error', $exception->getMessage(), 'Fix the env files or the ownership of logs/ and temp/; the remaining checks need them.');

            return null;
        }
        $this->add('environment', 'ok', "APP_ENV={$config->environment->env}", null);

        return $config;
    }

    private function checkDirectories(): void
    {
        foreach (['logs' => $this->paths->logsPath, 'temp' => $this->paths->tempPath, 'cache' => $this->paths->tempPath . '/api-cache'] as $name => $dir) {
            $ok = is_dir($dir) && is_writable($dir);
            $this->add("dir:{$name}", $ok ? 'ok' : 'error', $dir . ($ok ? ' writable' : ' missing or not writable by ' . $this->currentUser()), $ok ? null : 'Give the web server user write access');
        }
    }

    private function checkUsersFile(): void
    {
        try {
            $file = UsersFilePathResolver::resolve($this->paths);
        } catch (Throwable $exception) {
            $this->add('users', 'error', $exception->getMessage(), null);

            return;
        }
        if (!is_file($file)) {
            $this->add('users', 'error', "{$file} missing; every route fails until it exists", 'bin/mata user create <name> admin --generate-password');

            return;
        }
        $mode = fileperms($file) & 0o777;
        if (!is_readable($file) || !is_writable($file)) {
            $this->add('users', 'error', "{$file} not readable and writable by " . $this->currentUser(), null);
        } elseif (($mode & 0o077) !== 0) {
            $this->add('users', 'error', sprintf('%s has mode %o; logins fail until it is private', $file, $mode), "chmod 600 {$file}");
        } else {
            $this->add('users', 'ok', $file, null);
        }
    }

    private function checkSecretsFile(): void
    {
        $file = rtrim($this->configPath, '/') . '/' . ServersConfigLoader::SECRETS_FILE;
        if (!is_file($file)) {
            $this->add('secrets', 'warning', "{$file} missing; servers have no keys", 'bin/mata server add creates it');

            return;
        }
        $mode = fileperms($file) & 0o777;
        if (($mode & 0o007) !== 0) {
            $this->add('secrets', 'warning', sprintf('%s has mode %o; other users can read node keys', $file, $mode), "chmod 640 {$file} (group: the web server's)");
        } else {
            $this->add('secrets', 'ok', sprintf('%s mode %o', $file, $mode), null);
        }
    }

    private function checkDatabase(ApplicationConfig $config): void
    {
        try {
            new PdoDatabase($config->database);
            $pending = (new MigrationRunner($this->paths->appPath . '/migrations', $config->database, $config->environment->env))->pending();
        } catch (Throwable $exception) {
            $this->add('database', 'error', $exception->getMessage(), 'Check db.config.env and that the database is running');

            return;
        }
        $this->add('database', 'ok', "connected to {$config->database->host}", null);
        $this->add('migrations', $pending === [] ? 'ok' : 'warning', $pending === [] ? 'up to date' : count($pending) . ' pending', $pending === [] ? null : 'bin/mata db migrate');
    }

    private function checkOpa(ApplicationConfig $config): void
    {
        if ($config->alarmDecision->driver !== 'opa') {
            $this->add('opa', 'ok', "not used (ALARM_DECISION_DRIVER={$config->alarmDecision->driver})", null);

            return;
        }
        $url = rtrim($config->alarmDecision->opa->baseUri, '/') . '/health';

        try {
            $code = (new Client(['timeout' => 3, 'http_errors' => false]))->get($url)->getStatusCode();
        } catch (Throwable $exception) {
            $code = null;
        }
        $this->add('opa', $code === 200 ? 'ok' : 'error', $code === 200 ? "{$url} healthy" : "{$url} not reachable; alarms are not evaluated", $code === 200 ? null : 'make opa-start, or set ALARM_DECISION_DRIVER=builtin');
    }

    private function checkCron(): void
    {
        $reader = new CronHeartbeatReader($this->paths->appPath . '/cron', $this->paths->logsPath . '/cron');
        foreach ($reader->read(time()) as $job) {
            if ($job->job !== 'servers-data-fetch') {
                continue;
            }
            $level = match ($job->state) {
                CronJobState::Ok, CronJobState::Unknown => 'ok',
                CronJobState::Overdue => 'error',
                // Right after an install the first run is minutes away.
                CronJobState::Never, CronJobState::Pending => 'warning',
            };
            $hint = match (true) {
                $level === 'ok' => null,
                is_file(self::DOCKER_CRON_SETUP) => 'Cron in the web container runs it every 5 minutes; if it never does, check the container log (make logs)',
                default => 'Install the crontab (cron/crontab.shared-hosting.template); bin/mata status shows every job',
            };
            $this->add('cron', $level, "servers-data-fetch: {$job->state->value}", $hint);
        }
    }

    private function add(string $check, string $level, string $message, ?string $hint): void
    {
        $this->checks[] = ['check' => $check, 'level' => $level, 'message' => $message, 'hint' => $hint];
    }

    private function currentUser(): string
    {
        $user = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;

        return is_array($user) ? $user['name'] : 'the current user';
    }
}
