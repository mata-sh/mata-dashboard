<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Section;

/** Dashboard menu visibility overrides keyed by stable menu identifier. */
final readonly class MenuConfig
{
    /** @param array<string,bool> $overrides */
    private function __construct(public array $overrides) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param array<string,mixed>   $raw
     * @param list<string>          $validKeys
     * @param callable(string):void $reportInvalid
     */
    public static function fromArray(array $raw, array $validKeys, callable $reportInvalid): self
    {
        $overrides = [];
        foreach ($raw as $key => $value) {
            $key = (string) $key;
            if (!in_array($key, $validKeys, true)) {
                $reportInvalid("menu.json references unknown menu item '{$key}' -> ignored");

                continue;
            }
            if (!is_bool($value)) {
                $reportInvalid("menu.json entry '{$key}' must be a boolean -> ignored");

                continue;
            }
            $overrides[$key] = $value;
        }

        return new self($overrides);
    }
}
