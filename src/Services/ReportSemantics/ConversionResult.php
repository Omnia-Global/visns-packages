<?php

namespace Visnsstudio\VisnsPackages\Services\ReportSemantics;

/**
 * The outcome of upgrading one legacy (v1) report definition.
 *
 * Either a v2 definition plus the notes describing anything dropped or
 * approximated on the way, or - when nothing sensible could be produced - a
 * reason and no definition.
 */
final class ConversionResult
{
    /**
     * @param array|null $definition The v2 definition, or null when not convertible.
     * @param array<int, string> $notes Human-readable notes, in the order they arose.
     * @param string|null $reason Why the report is not convertible.
     */
    public function __construct(
        public readonly ?array $definition,
        public readonly array $notes = [],
        public readonly ?string $reason = null
    ) {
    }

    public static function converted(array $definition, array $notes): self
    {
        return new self($definition, array_values($notes));
    }

    public static function notConvertible(string $reason, array $notes = []): self
    {
        return new self(null, array_values($notes), $reason);
    }

    public function isConvertible(): bool
    {
        return $this->definition !== null;
    }
}
