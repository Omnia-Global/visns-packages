<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\CallQueue;

use Visnsstudio\VisnsPackages\Models\ZoomLiveQueueCall;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * The leg arithmetic behind "if it stops ringing, it should close for everyone".
 *
 * Zoom sends `phone.callee_ringing` and `phone.callee_ended` PER LEG on one
 * `call_id`: a queue rings every member, and each member's extension rings a
 * desk phone AND the Zoom app. The pop closes when the number of legs still
 * ringing reaches zero, so the counting is the whole feature — and it is model
 * state, not HTTP, so it is tested here without a webhook in sight. The
 * end-to-end behaviour lives in CallQueueWebhookTest.
 */
class CallQueueLegsTest extends TestCase
{
    private function liveCall(): ZoomLiveQueueCall
    {
        // Never saved: every method under test is pure model state.
        return new ZoomLiveQueueCall(['call_id' => 'call-legs-1']);
    }

    /*
    |--------------------------------------------------------------------------
    | Keys
    |--------------------------------------------------------------------------
    */

    public function test_a_leg_is_keyed_by_its_device_when_zoom_names_one(): void
    {
        // The only field that tells a desk phone from the same person's Zoom
        // app — the two legs share every other identifier they have.
        $this->assertSame(
            'device-aaa',
            $this->liveCall()->legKeyFor([
                'device_id' => 'device-aaa',
                'extension_id' => 'user-9',
                'id' => 'user-9',
            ])
        );
    }

    public function test_the_key_falls_back_through_the_ids_zoom_does_send(): void
    {
        $call = $this->liveCall();

        $this->assertSame('user-9', $call->legKeyFor(['extension_id' => 'user-9']));
        $this->assertSame('queue-1', $call->legKeyFor(['id' => 'queue-1']));
        $this->assertSame('u-42', $call->legKeyFor(['user_id' => 'u-42']));
    }

    public function test_an_unidentifiable_leg_still_gets_counted(): void
    {
        $call = $this->liveCall();

        // A closing payload is often nothing but a call_id. The leg still has
        // to occupy a slot, so it is given a positional one.
        $this->assertSame('leg-1', $call->legKeyFor([]));

        $call->recordRingingLeg([]);

        $this->assertSame('leg-2', $call->legKeyFor([]));
    }

    /*
    |--------------------------------------------------------------------------
    | Recording
    |--------------------------------------------------------------------------
    */

    public function test_a_fresh_row_has_no_legs_at_all(): void
    {
        $call = $this->liveCall();

        // "No legs recorded" is not "nothing ringing" — it is "we do not know",
        // which is what makes a row from the previous release close on the
        // first ended event it sees.
        $this->assertFalse($call->hasRecordedLegs());
        $this->assertSame(0, $call->ringingLegCount());
    }

    public function test_two_devices_of_one_extension_are_two_legs(): void
    {
        $call = $this->liveCall();

        // Zoom did not distinguish them, so the same key arrives twice. It must
        // become two entries: collapsing them is the original bug, where one
        // ended event closed a call two handsets were still ringing.
        $call->recordRingingLeg(['extension_id' => '204']);
        $call->recordRingingLeg(['extension_id' => '204']);

        // Cast because PHP turns a numeric-string array key into an int, both
        // here and on the way back out of the JSON column — harmless, since
        // every lookup coerces the same way, but it shows up in an assertion.
        $this->assertSame(
            ['204', '204#2'],
            array_map('strval', array_keys($call->legs))
        );
        $this->assertSame(2, $call->ringingLegCount());
    }

    public function test_distinct_devices_keep_their_own_keys(): void
    {
        $call = $this->liveCall();

        $call->recordRingingLeg(['extension_id' => '204', 'device_id' => 'desk']);
        $call->recordRingingLeg(['extension_id' => '204', 'device_id' => 'app']);

        $this->assertSame(['desk', 'app'], array_keys($call->legs));
        $this->assertSame(2, $call->ringingLegCount());
    }

    public function test_a_settled_leg_ringing_again_reuses_its_slot(): void
    {
        $call = $this->liveCall();

        $call->recordRingingLeg(['extension_id' => '204']);
        $call->settleLeg(['extension_id' => '204'], ZoomLiveQueueCall::LEG_MISSED);

        // Zoom's queue overflow re-offers the same call_id to a member who
        // already declined it. That is the same leg ringing again, not a new
        // one, so the map must not grow every time the queue goes round.
        $call->recordRingingLeg(['extension_id' => '204']);

        $this->assertSame(['204'], array_map('strval', array_keys($call->legs)));
        $this->assertSame(1, $call->ringingLegCount());
    }

    /*
    |--------------------------------------------------------------------------
    | Settling
    |--------------------------------------------------------------------------
    */

    public function test_settling_takes_the_legs_down_one_at_a_time(): void
    {
        $call = $this->liveCall();

        $call->recordRingingLeg(['extension_id' => '204']);
        $call->recordRingingLeg(['extension_id' => '204']);

        $this->assertTrue(
            $call->settleLeg(['extension_id' => '204'], ZoomLiveQueueCall::LEG_ENDED)
        );

        // The first still-ringing sibling settles; the other keeps the call up.
        $this->assertSame(1, $call->ringingLegCount());
        $this->assertSame('ended', $call->legs['204']['state']);
        $this->assertSame('ringing', $call->legs['204#2']['state']);

        $this->assertTrue(
            $call->settleLeg(['extension_id' => '204'], ZoomLiveQueueCall::LEG_ENDED)
        );

        $this->assertSame(0, $call->ringingLegCount());
    }

    public function test_a_repeated_delivery_settles_nothing_twice(): void
    {
        $call = $this->liveCall();

        $call->recordRingingLeg(['device_id' => 'desk']);
        $call->recordRingingLeg(['device_id' => 'app']);

        $call->settleLeg(['device_id' => 'desk'], ZoomLiveQueueCall::LEG_ENDED);

        // Zoom retries. A second delivery of an event whose leg is already
        // settled must not take an innocent leg down with it — the retry would
        // otherwise close a call that is still ringing on the other handset.
        $this->assertFalse(
            $call->settleLeg(['device_id' => 'desk'], ZoomLiveQueueCall::LEG_ENDED)
        );

        $this->assertSame(1, $call->ringingLegCount());
        $this->assertSame('ringing', $call->legs['app']['state']);
    }

    public function test_a_leg_zoom_did_not_name_settles_the_first_one_ringing(): void
    {
        $call = $this->liveCall();

        $call->recordRingingLeg(['extension_id' => '203']);
        $call->recordRingingLeg(['extension_id' => '206']);

        // Closing payloads frequently carry nothing but the call id. Which leg
        // it was does not matter; how many are left does.
        $this->assertTrue($call->settleLeg([], ZoomLiveQueueCall::LEG_ENDED));

        $this->assertSame(1, $call->ringingLegCount());
        $this->assertSame('ended', $call->legs['203']['state']);
    }

    public function test_settling_a_row_with_no_legs_reports_that_it_did_nothing(): void
    {
        // The signal the controller reads as "this row predates the leg map".
        $this->assertFalse(
            $this->liveCall()->settleLeg(
                ['extension_id' => '204'],
                ZoomLiveQueueCall::LEG_ENDED
            )
        );
    }

    public function test_a_leg_records_its_state_and_the_moment_it_changed(): void
    {
        $call = $this->liveCall();

        $call->recordRingingLeg(['device_id' => 'desk']);

        $this->assertSame('ringing', $call->legs['desk']['state']);
        $this->assertNotEmpty($call->legs['desk']['at']);

        $call->settleLeg(['device_id' => 'desk'], ZoomLiveQueueCall::LEG_MISSED);

        $this->assertSame('missed', $call->legs['desk']['state']);
    }
}
