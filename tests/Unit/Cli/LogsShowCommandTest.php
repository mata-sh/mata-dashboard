<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Cli;

use DateTimeImmutable;
use Mata\Cli\CliConfig;
use Mata\Cli\Command\Logs\LogsShowCommand;
use Mata\Utils\PathResolver;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * @covers \Mata\Cli\Command\LogsShowCommand
 *
 * @internal
 */
final class LogsShowCommandTest extends TestCase
{
    /** An agent must not read "no errors in the last day" when the node cut that day off. */
    public function testSinceWindowIsNotCoveredWhenTheNodeCappedTheFile(): void
    {
        $recent = (new DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        $old = (new DateTimeImmutable('-3 days'))->format('Y-m-d H:i:s');
        $result = [
            'entries' => [
                'busy.log' => [
                    ['timestamp' => $recent, 'type' => 'Other', 'message' => 'a'],
                    ['timestamp' => $recent, 'type' => 'Other', 'message' => 'b'],
                ],
                'quiet.log' => [['timestamp' => $old, 'type' => 'Error', 'message' => 'c']],
                'new.log' => [['timestamp' => $recent, 'type' => 'Error', 'message' => 'd']],
            ],
            'readout_limit' => 2,
        ];

        $data = $this->extract($result, ['--since' => '1d', '--type' => 'error']);
        $files = array_column($data['files'], null, 'path');

        $this->assertFalse($files['busy.log']['covers_since']);
        $this->assertTrue($files['quiet.log']['covers_since']);
        $this->assertNull($files['new.log']['covers_since']);
        $this->assertSame(['d'], array_column($data['entries'], 'message'));
    }

    public function testEntriesOfAllFilesAreMergedNewestFirstAndCutToTheLimit(): void
    {
        $result = [
            'entries' => [
                'a.log' => [['timestamp' => '2026-10-01 10:00:00', 'message' => 'a1'], ['timestamp' => '2026-10-03 10:00:00', 'message' => 'a3']],
                'b.log' => [['timestamp' => '2026-10-02 10:00:00', 'message' => 'b2'], ['message' => 'no time']],
            ],
            'readout_limit' => 50,
        ];

        $data = $this->extract($result, ['--limit' => '3']);

        $this->assertSame(['a3', 'b2', 'a1'], array_column($data['entries'], 'message'));
        $this->assertTrue($data['truncated']);
        $this->assertSame(4, $data['matched']);
    }

    /**
     * @param array<string,mixed>  $result
     * @param array<string,string> $options
     *
     * @return array<string,mixed>
     */
    private function extract(array $result, array $options): array
    {
        $command = new LogsShowCommand(new CliConfig(PathResolver::fromAppPath(sys_get_temp_dir())));
        $input = new ArrayInput(['server' => 'web-01', 'app' => 'shop'] + $options, $command->getDefinition());

        return (new ReflectionMethod($command, 'extract'))->invoke($command, $result, $input);
    }
}
