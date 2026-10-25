<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Section\Advisory;

use SensitiveParameter;

/** Repository of a `github` advisory source. The token comes from the environment and never reaches logs. */
final readonly class GithubRepository
{
    /**
     * @param string  $name  "owner/name"
     * @param ?string $token raises the API rate limit and grants access to private repositories; null polls anonymously
     */
    public function __construct(
        public string $name,
        #[SensitiveParameter]
        public ?string $token,
    ) {}

    /** "owner/name" with GitHub's name characters; '.' and '..' segments would move apiUrl() off the repository. */
    public static function isValidName(string $name): bool
    {
        return preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#D', $name) === 1
            && array_intersect(explode('/', $name), ['.', '..']) === [];
    }

    /** The repository's published security advisories on the GitHub REST API. */
    public function apiUrl(): string
    {
        return "https://api.github.com/repos/{$this->name}/security-advisories";
    }
}
