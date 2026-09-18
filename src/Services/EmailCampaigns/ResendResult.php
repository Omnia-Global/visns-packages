<?php

namespace Visnsstudio\VisnsPackages\Services\EmailCampaigns;

/** What a Resend call came to: ok, the HTTP status, the body, and Resend's sentence. */
final class ResendResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?int $status,
        public readonly array $data,
        public readonly ?string $error,
    ) {
    }

    public static function success(?int $status, array $data): self
    {
        return new self(true, $status, $data, null);
    }

    public static function failure(?int $status, string $error, array $data = []): self
    {
        return new self(false, $status, $data, $error);
    }

    /** Rate-limited: try again next tick rather than fail the member. */
    public function throttled(): bool
    {
        return $this->status === 429;
    }
}
