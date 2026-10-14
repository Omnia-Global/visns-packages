<?php

namespace Visnsstudio\VisnsPackages\Tests\Fixtures\Messaging;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Visnsstudio\VisnsPackages\Controllers\SmsCampaignController;
use Visnsstudio\VisnsPackages\Models\SmsLine;

/**
 * What a host application's client portal does with the campaign screen:
 * extend it, point the hooks at one client, mount it behind its own guard.
 *
 * Client 7, and an actor who is not a row in `users` - the shape a person
 * signed in to a client portal has.
 */
class ClientCampaignController extends SmsCampaignController
{
    public const CLIENT_ID = 7;

    public const ACTOR = 'Dana Client';

    protected function lineQuery(Request $request): Builder
    {
        return SmsLine::query()->forClient(self::CLIENT_ID);
    }

    protected function optOutClientId(Request $request): int
    {
        return self::CLIENT_ID;
    }

    protected function actor(Request $request): array
    {
        return ['user_id' => null, 'created_by_name' => self::ACTOR, 'user' => null];
    }
}
