<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use GuzzleHttp\Client;
use Mata\Cli\CliConfig;
use Mata\Cli\CliOutput;
use Mata\Config\Dto\ApplicationConfig;
use Mata\Config\Monitoring\Loader\ServersConfigLoader;
use Mata\Config\Validation\InstanceConfigValidator;
use Mata\Database\MigrationRunner;
use Mata\Database\PdoDatabase;
use Mata\Services\Alarm\Evaluation\OpaAlarmDecisionService;
use Mata\Services\Cron\CronHeartbeatReader;
use Mata\Services\Cron\Dto\CronJobState;
use Mata\Services\Status\StatusLevel;
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
    /** @var list<array{check:string,level:StatusLevel,message:string,hint:?string}> */
    private array $checks = [];

    public function __construct(
        private readonly CliConfig $cli,
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

        $level = StatusLevel::worst(array_column($this->checks, 'level'));

        if ($input->getOption('json')) {
            $checks = array_map(static fn (array $check): array => ['level' => $check['level']->label()] + $check, $this->checks);
            CliOutput::json($output, ['status' => $level->label(), 'checks' => $checks]);
        } else {
            $this->writeHuman($output);
        }

        return $level->value;
    }

    private function writeHuman(OutputInterface $output): void
    {
        foreach ($this->checks as $check) {
            $output->writeln('  ' . CliOutput::mark($check['level']) . " {$check['check']}: {$check['message']}");
            if ($check['hint'] !== null) {
                $output->writeln("      <comment>{$check['hint']}</comment>");
            }
        }
    }

    private function checkConfig(): ?ApplicationConfig
    {
        $report = (new InstanceConfigValidator())->validate($this->cli->configPath);
        $level = $report->level();
        $this->add('config', $level, count($report->problems) . ' problem(s) in ' . $report->configPath, hint: $level === StatusLevel::Ok ? null : 'bin/mata config validate');

        try {
            $config = $this->cli->load();
        } catch (Throwable $exception) {
            $this->add('environment', StatusLevel::Error, $exception->getMessage(), hint: 'Fix the env files or the ownership of logs/ and temp/; the remaining checks need them.');

            return null;
        }
        $this->add('environment', StatusLevel::Ok, "APP_ENV={$config->environment->env}");

        return $config;
    }

    private function checkDirectories(): void
    {
        $paths = $this->cli->paths();
        foreach (['logs' => $paths->logsPath, 'temp' => $paths->tempPath, 'cache' => $paths->apiCachePath] as $name => $dir) {
            if (is_dir($dir) && is_writable($dir)) {
                $this->add("dir:{$name}", StatusLevel::Ok, "{$dir} writable");
            } else {
                $this->add("dir:{$name}", StatusLevel::Error, "{$dir} missing or not writable by {$this->currentUser()}", hint: 'Give the web server user write access');
            }
        }
    }

    private function checkUsersFile(): void
    {
        try {
            $file = UsersFilePathResolver::resolve($this->cli->paths());
        } catch (Throwable $exception) {
            $this->add('users', StatusLevel::Error, $exception->getMessage());

            return;
        }
        if (!is_file($file)) {
            $this->add('users', StatusLevel::Error, "{$file} missing; every route fails until it exists", hint: 'bin/mata user create <name> admin --generate-password');

            return;
        }
        $mode = fileperms($file) & 0o777;
        if (!is_readable($file) || !is_writable($file)) {
            $this->add('users', StatusLevel::Error, "{$file} not readable and writable by " . $this->currentUser());
        } elseif (($mode & 0o077) !== 0) {
            $this->add('users', StatusLevel::Error, sprintf('%s has mode %o; logins fail until it is private', $file, $mode), hint: "chmod 600 {$file}");
        } else {
            $this->add('users', StatusLevel::Ok, $file);
        }
    }

    private function checkSecretsFile(): void
    {
        $file = rtrim($this->cli->configPath, '/') . '/' . ServersConfigLoader::SECRETS_FILE;
        if (!is_file($file)) {
            $this->add('secrets', StatusLevel::Warning, "{$file} missing; servers have no keys", hint: 'bin/mata server add creates it');

            return;
        }
        $mode = fileperms($file) & 0o777;
        if (($mode & 0o007) !== 0) {
            $this->add('secrets', StatusLevel::Warning, sprintf('%s has mode %o; other users can read node keys', $file, $mode), hint: "chmod 640 {$file} (group: the web server's)");
        } else {
            $this->add('secrets', StatusLevel::Ok, sprintf('%s mode %o', $file, $mode));
        }
    }

    private function checkDatabase(ApplicationConfig $config): void
    {
        try {
            // Connects the way the app does; Phinx below connects through its own adapter.
            new PdoDatabase($config->database);
            $pending = (new MigrationRunner($this->cli->paths()->appPath . '/migrations', $config->database, $config->environment->env))->pending();
        } catch (Throwable $exception) {
            $this->add('database', StatusLevel::Error, $exception->getMessage(), hint: 'Check db.config.env and that the database is running');

            return;
        }
        $this->add('database', StatusLevel::Ok, "connected to {$config->database->host}");
        if ($pending === []) {
            $this->add('migrations', StatusLevel::Ok, 'up to date');
        } else {
            $this->add('migrations', StatusLevel::Warning, count($pending) . ' pending', hint: 'bin/mata db migrate');
        }
    }

    private function checkOpa(ApplicationConfig $config): void
    {
        if ($config->alarmDecision->driver !== 'opa') {
            $this->add('opa', StatusLevel::Ok, "not used (ALARM_DECISION_DRIVER={$config->alarmDecision->driver})");

            return;
        }
        $url = rtrim($config->alarmDecision->opa->baseUri, '/') . '/health';

        try {
            $code = (new Client(['timeout' => 3, 'http_errors' => false]))->get($url)->getStatusCode();
        } catch (Throwable) {
            $code = null;
        }
        if ($code !== 200) {
            $this->add('opa', StatusLevel::Error, "{$url} not reachable; alarms are not evaluated", hint: 'make opa-start, or set ALARM_DECISION_DRIVER=builtin');

            return;
        }

        // A healthy OPA still answers {} for a package it has not loaded (a typo in the path, a
        // deleted config/policy/ file), and every evaluation then fails.
        $path = $config->alarmDecision->opa->decisionPath;

        try {
            (new OpaAlarmDecisionService($config->alarmDecision->opa, 1))->decide(['type' => 'doctor_probe', 'level' => 'info']);
            $this->add('opa', StatusLevel::Ok, "{$url} healthy, decisions from {$path}");
        } catch (Throwable $exception) {
            $this->add('opa', StatusLevel::Error, "{$path} gave no decision ({$exception->getMessage()}); alarms are not evaluated", hint: 'Check OPA_ALARM_DECISION_PATH and the package in config/policy/; make opa-test shows policy errors');
        }
    }

    private function checkCron(): void
    {
        $reader = CronHeartbeatReader::fromPaths($this->cli->paths());
        foreach ($reader->read(time()) as $cron) {
            if ($cron->job !== 'servers-data-fetch') {
                continue;
            }
            $level = match ($cron->state) {
                CronJobState::Ok, CronJobState::Unknown => StatusLevel::Ok,
                CronJobState::Overdue => StatusLevel::Error,
                // Right after an install the first run is minutes away.
                CronJobState::Never, CronJobState::Pending => StatusLevel::Warning,
            };
            $hint = match (true) {
                $level === StatusLevel::Ok => null,
                // The Docker image sets it: cron runs in the web container, not from a host crontab.
                getenv('MATA_CRON') === 'container' => 'Cron in the web container runs it every 5 minutes; if it never does, check the container log (make logs)',
                default => 'Install the crontab (cron/crontab.shared-hosting.template); bin/mata status shows every job',
            };
            $this->add('cron', $level, "servers-data-fetch: {$cron->state->value}", hint: $hint);
        }
    }

    private function add(string $check, StatusLevel $level, string $message, ?string $hint = null): void
    {
        $this->checks[] = ['check' => $check, 'level' => $level, 'message' => $message, 'hint' => $hint];
    }

    private function currentUser(): string
    {
        return CliOutput::currentUser() ?? 'the current user';
    }
}
