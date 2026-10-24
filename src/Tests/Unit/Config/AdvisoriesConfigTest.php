<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Config;

use InvalidArgumentException;
use Mata\Config\Monitoring\Section\AdvisoriesConfig;
use Mata\Config\Monitoring\Section\Advisory\AdvisorySourceType;
use Mata\Services\Audit\Severity;
use Mata\Tests\TestCase;

/**
 * @covers \Mata\Config\Monitoring\Section\AdvisoriesConfig
 *
 * @internal
 */
final class AdvisoriesConfigTest extends TestCase
{
    /** @var list<string> */
    private array $problems = [];

    public function testTheExampleFileLoadsEverySourceWithoutProblems(): void
    {
        $raw = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/config.example/advisories.json.example'), true, flags: JSON_THROW_ON_ERROR);

        $config = $this->parse($raw);

        $this->assertSame([], $this->problems);
        $this->assertSame(['cert-bund', 'php-src', 'jquery', 'jquery-ui', 'oss-security', 'twig', 'cert-bund-csaf'], array_keys($config->sources));
        $this->assertSame(['cert-bund', 'php-src', 'jquery', 'jquery-ui'], array_keys($config->enabledSources()), 'The disabled mailbox needs no credentials.');
        $this->assertSame('https://api.github.com/repos/php/php-src/security-advisories', $config->sources['php-src']->url);
        $this->assertSame(Severity::Medium, $config->sources['cert-bund']->minSeverity);
        $this->assertFalse($config->sources['oss-security']->notify);
    }

    public function testAnInvalidSourceIsSkippedAndReportedWhileTheOthersLoad(): void
    {
        $config = $this->parse(['sources' => [
            'good' => ['type' => 'feed', 'url' => 'https://example.org/rss'],
            'plain-http' => ['type' => 'feed', 'url' => 'http://example.org/rss'],
            'typo' => ['type' => 'feed', 'url' => 'https://example.org/rss', 'filter' => []],
            'float' => ['type' => 'feed', 'url' => 'https://example.org/rss', 'poll_interval_minutes' => 60.0],
            'imap-no-secret' => ['type' => 'imap', 'host' => 'imap.example.org', 'username_env' => 'ADVISORY_TEST_UNSET_USER', 'password_env' => 'ADVISORY_TEST_UNSET_PASSWORD'],
        ]]);

        $this->assertSame(['good'], array_keys($config->sources));
        $this->assertCount(4, $this->problems);
        $this->assertStringContainsString('https URL', $this->problems[0]);
        $this->assertStringContainsString('unknown key filter', $this->problems[1]);
        $this->assertStringContainsString('must be an integer', $this->problems[2]);
        $this->assertStringContainsString('ADVISORY_TEST_UNSET_USER is not set', $this->problems[3]);
    }

    public function testSecretsAreReadFromTheEnvironmentAndNeverEchoed(): void
    {
        $env = static fn (string $name): ?string => ['ADV_USER' => 'robot', 'ADV_PASS' => 's3cr3t', 'GH_TOKEN' => 'ghp_x'][$name] ?? null;
        $config = AdvisoriesConfig::fromArray(['sources' => [
            'list' => ['type' => 'imap', 'host' => 'imap.example.org', 'encryption' => 'starttls', 'username_env' => 'ADV_USER', 'password_env' => 'ADV_PASS', 'addresses' => ['List@Example.org']],
            'repo' => ['type' => 'github', 'repository' => 'php/php-src', 'token_env' => 'GH_TOKEN'],
        ]], $env, $this->collect(...));

        $this->assertSame('robot', $config->sources['list']->mailbox?->username);
        $this->assertSame(143, $config->sources['list']->mailbox?->port);
        $this->assertSame(['list@example.org'], $config->sources['list']->mailbox?->addresses);
        $this->assertStringNotContainsString('s3cr3t', $config->sources['list']->url);
        $this->assertSame('ghp_x', $config->sources['repo']->github?->token);
        $this->assertSame(AdvisorySourceType::Github, $config->sources['repo']->type);
    }

    public function testUnencryptedImapIsRejected(): void
    {
        $this->parse(['sources' => ['list' => ['type' => 'imap', 'host' => 'imap.example.org', 'encryption' => 'none', 'enabled' => false,
            'username_env' => 'U', 'password_env' => 'P']]]);

        $this->assertStringContainsString('never sent unencrypted', $this->problems[0] ?? '');
    }

    public function testGithubRepositoryCannotLeaveTheRepositoryPath(): void
    {
        $config = $this->parse(['sources' => ['up' => ['type' => 'github', 'repository' => '../..']]]);

        $this->assertSame([], $config->sources);
        $this->assertStringContainsString('must be "owner/name"', $this->problems[0] ?? '');
    }

    public function testAnInvalidTopLevelSettingRejectsTheFile(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->parse(['failure_warning_after' => 0, 'sources' => []]);
    }

    /** @param array<string,mixed> $raw */
    private function parse(array $raw): AdvisoriesConfig
    {
        return AdvisoriesConfig::fromArray($raw, static fn (string $name): ?string => null, $this->collect(...));
    }

    private function collect(string $problem): void
    {
        $this->problems[] = $problem;
    }
}
