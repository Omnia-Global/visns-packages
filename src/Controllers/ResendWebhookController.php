<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Visnsstudio\VisnsPackages\Services\EmailCampaigns\ResendWebhook;

/**
 * `POST api/resend/webhook` — Resend (via Svix) reporting on campaign emails.
 *
 * 401 for anything unsigned or stale, and nothing else is ever an error: a
 * signed event this module does not care about (a transactional email, an
 * unknown broadcast) is still a 200, or Svix would retry it for days.
 */
class ResendWebhookController extends Controller
{
    public function __invoke(Request $request, ResendWebhook $webhook)
    {
        $body = (string) $request->getContent();
        $id = $request->header('svix-id');

        if (! $webhook->verify($body, $id, $request->header('svix-timestamp'), $request->header('svix-signature'))) {
            return response()->json(['ok' => false], 401);
        }

        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return response()->json(['ok' => true, 'outcome' => 'unreadable']);
        }

        try {
            $outcome = $webhook->handle($payload, (string) $id);
        } catch (\Throwable $e) {
            Log::warning('email_campaigns.webhook_failed', ['type' => $payload['type'] ?? null, 'error' => $e->getMessage()]);
            $outcome = 'error';
        }

        return response()->json(['ok' => true, 'outcome' => $outcome]);
    }
}
