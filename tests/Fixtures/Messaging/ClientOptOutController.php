<?php

namespace Visnsstudio\VisnsPackages\Tests\Fixtures\Messaging;

use Illuminate\Http\Request;
use Visnsstudio\VisnsPackages\Controllers\SmsOptOutController;

/**
 * The opt-out register as a client portal would mount it: client 7's register,
 * recorded by somebody who is not a row in `users`.
 */
class ClientOptOutController extends SmsOptOutController
{
    protected function optOutClientId(Request $request): int
    {
        return ClientCampaignController::CLIENT_ID;
    }

    protected function actor(Request $request): array
    {
        return ['user_id' => null, 'created_by_name' => ClientCampaignController::ACTOR, 'user' => null];
    }
}
