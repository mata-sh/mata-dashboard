<?php

declare(strict_types=1);

namespace Mata\Database;

use Mata\Config\Dto\DatabaseConfig;
use Phinx\Config\Config;
use Phinx\Migration\Manager;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Phinx in-process with the credentials from db.config.env, so no config file with the
 * database password is written to disk.
 */
final class MigrationRunner
{
    private Manager $manager;

    public function __construct(
        string $migrationsPath,
        DatabaseConfig $db,
        private string $env,
        ?OutputInterface $output = null,
    ) {
        $this->manager = new Manager(new Config([
            'paths' => ['migrations' => $migrationsPath],
            'environments' => [
                'default_migration_table' => 'phinxlog',
                'default_environment' => $env,
                $env => [
                    'adapter' => 'mysql',
                    'host' => $db->host,
                    'name' => $db->database,
                    'user' => $db->username,
                    'pass' => $db->password,
                    'port' => $db->port,
                    'charset' => 'utf8mb4',
                ],
            ],
            'version_order' => 'creation',
        ]), new ArrayInput([]), $output ?? new BufferedOutput());
    }

    /**
     * Migration files not recorded in phinxlog. A reused timestamp counts as applied, so a
     * migration renamed onto an old version is never listed here.
     *
     * @return list<array{version:int,name:string}>
     */
    public function pending(): array
    {
        $applied = array_flip($this->manager->getEnvironment($this->env)->getVersions());
        $pending = [];
        foreach ($this->manager->getMigrations($this->env) as $version => $migration) {
            if (!isset($applied[$version])) {
                $pending[] = ['version' => (int) $version, 'name' => $migration->getName()];
            }
        }

        return $pending;
    }

    public function printStatus(): void
    {
        $this->manager->printStatus($this->env);
    }

    public function migrate(): void
    {
        $this->manager->migrate($this->env);
    }

    public function rollback(): void
    {
        $this->manager->rollback($this->env);
    }
}
