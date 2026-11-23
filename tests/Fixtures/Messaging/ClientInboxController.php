<?php

namespace Visnsstudio\VisnsPackages\Tests\Fixtures\Messaging;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Visnsstudio\VisnsPackages\Controllers\SmsController;
use Visnsstudio\VisnsPackages\Models\SmsLine;
use Visnsstudio\VisnsPackages\Models\SmsThread;
use Visnsstudio\VisnsPackages\Models\SmsThreadReaderMark;

/**
 * What a host application's client portal does with the inbox: extend it,
 * point the hooks at one client, mount it behind its own guard.
 *
 * Client 7, and a reader who is not a row in `users` - the shape a person
 * signed in to a client portal has. The reader id is a static the test
 * switches, standing in for "whoever the portal guard says is signed in".
 */
class ClientInboxController extends SmsController
{
    public const CLIENT_ID = 7;

    public const READER_TYPE = 'portal_user';

    public const SENDER = 'Jane Okoro';

    public static int $readerId = 1;

    protected function lineQuery(Request $request): Builder
    {
        return SmsLine::query()->forClient(self::CLIENT_ID);
    }

    protected function lineIds(Request $request): array
    {
        return $this->lineQuery($request)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    protected function threadQuery(Request $request): Builder
    {
        return SmsThread::query()->whereIn(
            'line_id',
            $this->lineQuery($request)->select('id')
        );
    }

    protected function unreadCounts(Request $request, ?array $threadIds = null, ?array $lineIds = null): array
    {
        return SmsThread::unreadCountsForReader(self::READER_TYPE, self::$readerId, $threadIds, $lineIds);
    }

    protected function markThreadRead(Request $request, SmsThread $thread): void
    {
        $newest = $thread->messages()->max('id');

        SmsThreadReaderMark::mark(
            (int) $thread->id,
            self::READER_TYPE,
            self::$readerId,
            $newest === null ? null : (int) $newest
        );
    }

    protected function sender(Request $request): array
    {
        return ['user' => null, 'name' => self::SENDER];
    }

    protected function optOutClientId(Request $request): int
    {
        return self::CLIENT_ID;
    }

    protected function presentThread(Request $request, array $payload): array
    {
        // The host's client link is the host's business, never the client's.
        unset($payload['client']);

        return $payload;
    }
}
