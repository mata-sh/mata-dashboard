<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Bootstrap;

use Mata\Config\Monitoring\MonitoringConfigLoader;
use Mata\Tests\TestCase;
use ReflectionClass;
use ReflectionParameter;

/**
 * @internal
 *
 * @coversNothing
 */
final class MonitoringLoaderParityTest extends TestCase
{
    /** Web-only loaders the cron bootstrap deliberately omits. */
    private const CRON_OMITS = ['menu', 'charts'];

    /** @var list<string> */
    private array $fixtures = [];

    protected function tearDown(): void
    {
        $this->cleanupTempFiles($this->fixtures);
        $this->fixtures = [];
    }

    public function testWebBootstrapWiresEveryLoader(): void
    {
        $this->assertEqualsCanonicalizing(
            $this->constructorParameters(),
            $this->namedArguments($this->webBootstrapPath()),
            'public/index.php must pass every MonitoringConfigLoader argument.',
        );
    }

    public function testCronBootstrapWiresEveryLoaderExceptTheDocumentedOmissions(): void
    {
        $expected = array_values(array_diff($this->constructorParameters(), self::CRON_OMITS));

        $this->assertEqualsCanonicalizing(
            $expected,
            $this->namedArguments($this->cronBootstrapPath()),
            'MonitoringRuntime::build() drifted from public/index.php. '
            . 'Wire the loader there too, or add it to self::CRON_OMITS.',
        );
    }

    public function testLoadersOmittedByCronStayOptional(): void
    {
        $constructor = (new ReflectionClass(MonitoringConfigLoader::class))->getConstructor();
        self::assertNotNull($constructor);

        $optional = [];
        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->isOptional()) {
                $optional[] = $parameter->getName();
            }
        }

        foreach (self::CRON_OMITS as $name) {
            $this->assertContains(
                $name,
                $optional,
                "MonitoringConfigLoader::\${$name} is required but MonitoringRuntime omits it - cron would fatal.",
            );
        }
    }

    public function testServerLoaderFallbackDiffersByEntrypointOnPurpose(): void
    {
        $this->assertStringContainsString(
            'fallbackOnFailure: true',
            $this->namedArgumentSources($this->webBootstrapPath())['servers'],
            'The web dashboard must still render when servers.json is unreadable.',
        );

        $this->assertStringContainsString(
            'fallbackOnFailure: false',
            $this->namedArgumentSources($this->cronBootstrapPath())['servers'],
            'A cron job with no server list must fail loudly instead of silently doing nothing.',
        );
    }

    public function testExtractionIgnoresNestedNamedArgumentsAndDetectsAMissingLoader(): void
    {
        $complete = $this->fixture(<<<'PHP'
            <?php
            $loader = new MonitoringConfigLoader(
                servers: new ServersConfigLoader(eventRecorder: $r, fallbackOnFailure: false),
                ttl: new TtlConfigLoader(eventRecorder: $r, fallbackOnFailure: true),
                menu: new MenuConfigLoader(eventRecorder: $r, validKeys: ['a' => ['b']], fallbackOnFailure: true),
            );
            PHP);

        $this->assertSame(['servers', 'ttl', 'menu'], $this->namedArguments($complete));
        $this->assertStringContainsString(
            'fallbackOnFailure: false',
            $this->namedArgumentSources($complete)['servers'],
        );

        $drifted = $this->fixture(<<<'PHP'
            <?php
            $loader = new MonitoringConfigLoader(
                servers: new ServersConfigLoader(eventRecorder: $r, fallbackOnFailure: false),
                ttl: new TtlConfigLoader(eventRecorder: $r, fallbackOnFailure: true),
            );
            PHP);

        $this->assertSame(['servers', 'ttl'], $this->namedArguments($drifted));
    }

    private function fixture(string $code): string
    {
        $path = $this->createTempFile($code, '.php');
        $this->fixtures[] = $path;

        return $path;
    }

    private function webBootstrapPath(): string
    {
        return dirname(__DIR__, 4) . '/public/index.php';
    }

    private function cronBootstrapPath(): string
    {
        return dirname(__DIR__, 4) . '/src/Bootstrap/MonitoringRuntime.php';
    }

    /** @return list<string> */
    private function constructorParameters(): array
    {
        $constructor = (new ReflectionClass(MonitoringConfigLoader::class))->getConstructor();
        self::assertNotNull($constructor);

        return array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            $constructor->getParameters(),
        );
    }

    /** @return list<string> */
    private function namedArguments(string $file): array
    {
        return array_keys($this->namedArgumentSources($file));
    }

    /**
     * Top-level named arguments of the `new MonitoringConfigLoader(...)` call in
     * $file, mapped to their raw source text. Nested ones are ignored.
     *
     * @return array<string, string>
     */
    private function namedArgumentSources(string $file): array
    {
        $source = file_get_contents($file);
        self::assertIsString($source, "Unable to read {$file}.");

        $tokens = token_get_all($source);
        $open = $this->findConstructorCall($tokens);
        self::assertNotNull($open, "No `new MonitoringConfigLoader(` found in {$file}.");

        $arguments = [];
        $current = null;
        $depth = 1;

        for ($i = $open + 1, $count = \count($tokens); $i < $count && $depth > 0; ++$i) {
            $token = $tokens[$i];

            if (\is_string($token)) {
                if ($token === '(' || $token === '[' || $token === '{') {
                    ++$depth;
                } elseif ($token === ')' || $token === ']' || $token === '}') {
                    --$depth;
                    if ($depth === 0) {
                        break;
                    }
                } elseif ($token === ',' && $depth === 1) {
                    $current = null;

                    continue;
                }
            } elseif ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                ++$depth;
            } elseif ($depth === 1 && $token[0] === T_STRING && $current === null) {
                $next = $this->nextSignificant($tokens, $i + 1);
                if ($next !== null && $tokens[$next] === ':') {
                    $current = $token[1];
                    $arguments[$current] = '';
                    $i = $next;

                    continue;
                }
            }

            if ($current !== null) {
                $arguments[$current] .= \is_string($token) ? $token : $token[1];
            }
        }

        return $arguments;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     */
    private function findConstructorCall(array $tokens): ?int
    {
        $target = (new ReflectionClass(MonitoringConfigLoader::class))->getShortName();

        for ($i = 0, $count = \count($tokens); $i < $count; ++$i) {
            $token = $tokens[$i];
            if (!\is_array($token) || $token[0] !== T_NEW) {
                continue;
            }

            $name = $this->nextSignificant($tokens, $i + 1);
            if ($name === null || !\is_array($tokens[$name]) || ltrim($tokens[$name][1], '\\') !== $target) {
                continue;
            }

            $paren = $this->nextSignificant($tokens, $name + 1);
            if ($paren !== null && $tokens[$paren] === '(') {
                return $paren;
            }
        }

        return null;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     */
    private function nextSignificant(array $tokens, int $from): ?int
    {
        for ($i = $from, $count = \count($tokens); $i < $count; ++$i) {
            $token = $tokens[$i];
            if (\is_string($token)) {
                return $i;
            }
            if (!\in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $i;
            }
        }

        return null;
    }
}
