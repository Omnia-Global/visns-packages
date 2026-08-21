<?php

namespace Visnsstudio\VisnsPackages\Tests\Fixtures\CallQueue;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Stands in for an application's own App\Events\CallQueueEnded.
 */
class AppCallQueueEnded
{
    use Dispatchable, SerializesModels;

    public string $callId;

    public function __construct(string $callId)
    {
        $this->callId = $callId;
    }
}
