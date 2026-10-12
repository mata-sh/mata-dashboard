<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Config;

use Mata\Config\Monitoring\Section\TtlConfig;
use Mata\Services\Cache\CacheType;
use Mata\Tests\TestCase;

/**
 * Guards the mechanism behind the TTL key drift, not just its symptoms.
 *
 * policy/ttl.rego's allowed_keys was originally copied from the example config rather
 * than derived from code, so it tracked configuration instead of consumers. Both failure
 * directions followed: errorlog survived years after its consumer was renamed away, and
 * health was never listed at all because it was added without touching the example.
 *
 * CacheType::ttlConfigKeys() is now the single source of truth and these assertions fail
 * if any of the three copies drift from it.
 *
 * @internal
 *
 * @coversNothing
 */
final class TtlConfigKeysAreAlignedTest extends TestCase
{
    public function testRegoAllowedKeysMatchTheEnum(): void
    {
        $this->assertSame(
            $this->sorted(CacheType::ttlConfigKeys()),
            $this->sorted($this->regoAllowedKeys()),
            'policy/ttl.rego allowed_keys has drifted from CacheType::ttlConfigKeys()',
        );
    }

    public function testDefaultConfigCoversEveryCacheType(): void
    {
        $defaults = TtlConfig::default()->values;

        $this->assertSame(
            $this->sorted(CacheType::ttlConfigKeys()),
            $this->sorted(array_keys($defaults)),
            'a cache type missing from the defaults resolves to TTL 0, disabling its caching',
        );
    }

    public function testExampleConfigCoversEveryCacheType(): void
    {
        $path = \dirname(__DIR__, 4) . '/config.example/ttl.json.example';
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);
        $this->assertSame(
            $this->sorted(CacheType::ttlConfigKeys()),
            $this->sorted(array_keys($decoded)),
            'ttl.json.example is the template operators copy, and the only file opa-validate reads',
        );
    }

    /**
     * The rego file is the contract OPA enforces, so it is parsed rather than duplicated.
     *
     * @return list<string>
     */
    private function regoAllowedKeys(): array
    {
        $rego = (string) file_get_contents(\dirname(__DIR__, 4) . '/policy/ttl.rego');

        $this->assertSame(1, preg_match('/allowed_keys\s*:=\s*\{(.*?)\}/s', $rego, $block));
        preg_match_all('/"([^"]+)"/', $block[1], $keys);

        return $keys[1];
    }

    /**
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private function sorted(array $keys): array
    {
        sort($keys);

        return $keys;
    }
}
