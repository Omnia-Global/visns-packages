<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\EmailCampaigns;

use App\Models\User;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Visnsstudio\VisnsPackages\Tests\Fixtures\EmailCampaigns\FakeContactSource;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * The email campaign module, with Resend faked at the HTTP layer.
 *
 * `$this->resend` answers every call the module makes from a small in-memory
 * model of Resend (contacts by email, segments, broadcasts) and records each
 * request as "METHOD path", so a test asserts on what went over the wire.
 */
abstract class EmailCampaignTestCase extends TestCase
{
    protected const BASE = '/ajax/email-campaigns';

    protected const SECRET = 'whsec_' . 'MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';

    /** @var array<int, string> */
    protected array $calls = [];

    /** @var array<string, array<string, mixed>> email => contact */
    protected array $contacts = [];

    /** A status code to answer every request with, when set. */
    protected ?int $failWith = null;

    /** Answer 429 after this many calls, when set. */
    protected ?int $throttleAfter = null;

    protected string $broadcastStatus = 'sent';

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('visns-packages.email_campaigns.enabled', true);
        $app['config']->set('visns-packages.email_campaigns.api_key', 're_test');
        $app['config']->set('visns-packages.email_campaigns.webhook_secret', self::SECRET);
        $app['config']->set('visns-packages.email_campaigns.from_name', 'Omnia Global');
        $app['config']->set('visns-packages.email_campaigns.from_email', 'news@omnia.test');
        $app['config']->set('visns-packages.email_campaigns.contact_source', FakeContactSource::class);
        $app['config']->set('visns-packages.email_campaigns.brand.company', 'Omnia Global');
        $app['config']->set('visns-packages.email_campaigns.brand.address', '1 Hay St, Perth WA 6000');
        // No pacing in tests: the sleep is replaced, but keep the budget roomy.
        $app['config']->set('visns-packages.email_campaigns.sync.per_second', 1000);
    }

    protected function defineDatabaseMigrations()
    {
        parent::defineDatabaseMigrations();

        $this->runPackageMigration('2026_09_18_180000_create_email_campaign_tables.php');
    }

    protected function setUp(): void
    {
        parent::setUp();

        FakeContactSource::reset();
        Cache::flush();
        $this->fakeResend();
    }

    protected function fakeResend(): void
    {
        Http::fake(function (ClientRequest $request) {
            $path = trim(parse_url($request->url(), PHP_URL_PATH), '/');
            $method = strtoupper($request->method());
            $this->calls[] = $method . ' ' . urldecode($path);
            $body = $request->data();

            if ($this->failWith) {
                return Http::response(['message' => 'Resend refused it.'], $this->failWith);
            }

            if ($this->throttleAfter !== null && count($this->calls) > $this->throttleAfter) {
                return Http::response(['message' => 'Too many requests.'], 429);
            }

            if ($path === 'segments' && $method === 'POST') {
                return Http::response(['object' => 'segment', 'id' => 'seg_' . count($this->calls)]);
            }

            if ($path === 'contact-properties') {
                return Http::response(['id' => 'prop_1']);
            }

            if (preg_match('#^contacts/([^/]+)/segments/([^/]+)$#', $path, $m)) {
                return Http::response(['id' => urldecode($m[2])]);
            }

            if (preg_match('#^contacts/([^/]+)$#', $path, $m) && $method === 'PATCH') {
                $email = urldecode($m[1]);

                if (! isset($this->contacts[$email])) {
                    return Http::response(['message' => 'Contact not found'], 404);
                }

                $this->contacts[$email] = $body + $this->contacts[$email];

                return Http::response(['object' => 'contact', 'id' => 'con_' . md5($email)]);
            }

            if ($path === 'contacts' && $method === 'POST') {
                $this->contacts[$body['email']] = $body;

                return Http::response(['object' => 'contact', 'id' => 'con_' . md5($body['email'])]);
            }

            if ($path === 'broadcasts' && $method === 'POST') {
                return Http::response(['id' => 'bc_1']);
            }

            if (preg_match('#^broadcasts/([^/]+)/(send|cancel)$#', $path, $m)) {
                return Http::response(['id' => $m[1]]);
            }

            if (preg_match('#^broadcasts/([^/]+)$#', $path, $m) && $method === 'GET') {
                return Http::response(['id' => $m[1], 'status' => $this->broadcastStatus, 'sent_at' => now()->toIso8601String()]);
            }

            if ($path === 'emails') {
                return Http::response(['id' => 'em_1']);
            }

            if (preg_match('#^segments/#', $path) && $method === 'DELETE') {
                return Http::response(['deleted' => true]);
            }

            return Http::response(['message' => 'Unexpected call ' . $method . ' ' . $path], 500);
        });
    }

    /** Every Resend request body sent to a path (first match). */
    protected function sent(string $method, string $path): ?array
    {
        foreach (Http::recorded() as [$request]) {
            if (strtoupper($request->method()) === $method && trim(parse_url($request->url(), PHP_URL_PATH), '/') === $path) {
                return $request->data();
            }
        }

        return null;
    }

    protected function staff(string ...$permissions): User
    {
        static $seq = 0;
        $seq++;

        $user = User::create([
            'name' => 'Dana Staff' . $seq,
            'firstname' => 'Dana',
            'surname' => 'Staff' . $seq,
            'email' => 'dana' . $seq . '@omnia.test',
            'password' => Hash::make('correct-horse'),
        ]);

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
            $user->givePermissionTo($name);
        }

        return $user;
    }

    protected function manager(): User
    {
        return $this->staff('Email Campaigns Access', 'Email Campaigns Manage');
    }

    /** A signed webhook delivery. */
    protected function webhook(array $payload, ?string $id = null, ?int $timestamp = null, ?string $secret = null)
    {
        $body = json_encode($payload);
        $id = $id ?? 'msg_' . uniqid();
        $timestamp = $timestamp ?? time();
        $key = base64_decode(substr($secret ?? self::SECRET, 6));
        $signature = base64_encode(hash_hmac('sha256', $id . '.' . $timestamp . '.' . $body, $key, true));

        return $this->call('POST', '/api/resend/webhook', [], [], [], [
            'HTTP_SVIX_ID' => $id,
            'HTTP_SVIX_TIMESTAMP' => (string) $timestamp,
            'HTTP_SVIX_SIGNATURE' => 'v1,' . $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }
}
