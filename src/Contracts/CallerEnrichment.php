<?php

namespace Visnsstudio\VisnsPackages\Contracts;

/**
 * Resolves a caller's phone number to whatever the application knows about them.
 *
 * Called once, on the webhook thread, when a call starts ringing - not by each
 * of the browsers watching the pop. The result is stored on the live-call row
 * and rides along in the broadcast.
 *
 * Enrichment is a nicety: an implementation that throws costs the pop its
 * client block, never the pop itself.
 */
interface CallerEnrichment
{
    /**
     * @param  string|null  $callerNumber  Whatever Zoom sent, e.g. "+61412345678".
     * @return array|null                  Any JSON-serialisable snapshot, or
     *                                     null when the number matched nobody.
     */
    public function __invoke(?string $callerNumber): ?array;
}
