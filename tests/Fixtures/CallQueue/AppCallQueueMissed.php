<?php

namespace Visnsstudio\VisnsPackages\Tests\Fixtures\CallQueue;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Stands in for an application's own App\Events\CallQueueMissed.
 *
 * Same constructor contract as the ended/answered pair — one leg stopping is
 * still just a call id.
 */
class AppCallQueueMissed
{
    use Dispatchable, SerializesModels;

    public string $callId;

    public function __construct(string $callId)
    {
        $this->callId = $callId;
    }
}
