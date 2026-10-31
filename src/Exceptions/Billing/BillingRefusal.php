<?php

namespace Visnsstudio\VisnsPackages\Exceptions\Billing;

use RuntimeException;

/**
 * A billing request refused with a sentence a person can act on.
 *
 * `status` is the HTTP answer: 422 for something the caller can fix, 409 for a
 * conflict (already included, a checkout already open), 404 for a record that
 * is not theirs. The message is shown as written.
 */
class BillingRefusal extends RuntimeException
{
    public function __construct(string $message, public int $status = 422)
    {
        parent::__construct($message);
    }

    public static function conflict(string $message): self
    {
        return new self($message, 409);
    }

    public static function notFound(): self
    {
        return new self('Not found.', 404);
    }
}
