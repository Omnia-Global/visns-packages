<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Messaging;

use Illuminate\Support\Facades\Broadcast;

/**
 * The private per-line broadcast channel.
 *
 * The rule has to match the HTTP endpoints exactly: a channel more generous than
 * the API is the way client conversations leak. So this exercises the callback
 * the provider registered, not a copy of it.
 */
class MessagingChannelTest extends MessagingTestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        // Off by default - an application may authorize the channel itself in
        // routes/channels.php.
        $app['config']->set('visns-packages.messaging.register_broadcast_channel', true);
    }

    /**
     * The callback the service provider registered for the sms-line pattern.
     *
     * Reached by reflection because Laravel's Broadcaster keeps its channel map
     * private; the alternative - restating the rule here - would test nothing.
     */
    private function channelCallback(): callable
    {
        $broadcaster = Broadcast::getFacadeRoot()->driver();

        $property = new \ReflectionProperty($broadcaster, 'channels');
        $property->setAccessible(true);

        $channels = $property->getValue($broadcaster);

        $this->assertArrayHasKey(
            'sms-line.{lineId}',
            $channels,
            'The messaging channel was not registered.'
        );

        return $channels['sms-line.{lineId}'];
    }

    public function test_a_member_is_admitted_to_their_own_lines_channel(): void
    {
        $member = $this->member();
        $line = $this->line([$member]);

        $this->assertTrue(($this->channelCallback())($member, (string) $line->id));
    }

    public function test_a_member_is_refused_a_line_they_are_not_attached_to(): void
    {
        $member = $this->member();
        $theirs = $this->line();

        $this->assertFalse(($this->channelCallback())($member, (string) $theirs->id));
    }

    public function test_an_administrator_is_admitted_to_every_line(): void
    {
        $admin = $this->admin();
        $line = $this->line();

        $this->assertTrue(($this->channelCallback())($admin, (string) $line->id));
    }

    public function test_a_line_that_does_not_exist_admits_nobody(): void
    {
        $member = $this->member();

        $this->assertFalse(($this->channelCallback())($member, '999'));
    }
}
