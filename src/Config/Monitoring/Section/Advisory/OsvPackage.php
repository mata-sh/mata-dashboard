<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Section\Advisory;

/** Package of an `osv` advisory source, named as OSV.dev names it. */
final readonly class OsvPackage
{
    /**
     * @param string $ecosystem OSV ecosystem, case-sensitive: npm, Packagist, PyPI, Debian:12 …
     * @param string $name      package name in that ecosystem, e.g. symfony/http-kernel
     */
    public function __construct(
        public string $ecosystem,
        public string $name,
    ) {}

    /** The package's vulnerability list on osv.dev. */
    public function pageUrl(): string
    {
        return 'https://osv.dev/list?' . http_build_query(['ecosystem' => $this->ecosystem, 'q' => $this->name]);
    }
}
