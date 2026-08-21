<?php

namespace Visnsstudio\VisnsPackages\Commands;

use Illuminate\Console\Command;
use Visnsstudio\VisnsPackages\Models\SmsLine;
use Visnsstudio\VisnsPackages\Models\SmsThread;
use Visnsstudio\VisnsPackages\Services\Sms\SmsService;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;
use Visnsstudio\VisnsPackages\Support\PhoneNumber;

/**
 * Pretend a text arrived, from the command line.
 *
 *     php artisan sms:simulate-inbound "+61812345678" "0412 345 678" "Running late"
 *     php artisan sms:simulate-inbound 3 0412345678 "Running late"
 *
 * The line can be given as an id or as its number. Everything else - the thread,
 * the client match, the unread count, the broadcast - happens exactly as it
 * would for a real webhook, because it goes through the same service.
 *
 * Refused while the Zoom transport is configured: on a connected system this
 * writes a message into a client's conversation that the client never sent, and
 * the thread is a business record.
 */
class SmsSimulateInboundCommand extends Command
{
    protected $signature = 'sms:simulate-inbound
        {line : The line id, or the line phone number}
        {from : The number the message came from}
        {body : The message text}';

    protected $description = 'Record an inbound SMS as if it had arrived from the provider';

    public function handle(SmsService $sms): int
    {
        if (! ModuleConfig::get('messaging.enabled', false)) {
            $this->error('Messaging is disabled (visns-packages.messaging.enabled).');

            return self::FAILURE;
        }

        if ($sms->transportName() === 'zoom') {
            $this->error('Refusing to simulate: the Zoom transport is connected.');

            return self::FAILURE;
        }

        $line = $this->resolveLine((string) $this->argument('line'));

        if ($line === null) {
            $this->error('No such line.');

            return self::FAILURE;
        }

        $country = (string) ModuleConfig::get('messaging.default_country', 'AU');
        $from = PhoneNumber::toE164((string) $this->argument('from'), $country);

        if ($from === null) {
            $this->error('That "from" number could not be read as a phone number.');

            return self::FAILURE;
        }

        $thread = SmsThread::findOrCreateFor($line, $from, $sms->clientResolver());

        $message = $sms->recordInbound($thread, (string) $this->argument('body'), [
            'raw_payload' => ['simulated' => true, 'source' => 'sms:simulate-inbound'],
        ]);

        if ($message === null) {
            $this->warn('Nothing recorded: that message already exists.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Recorded message #%d on thread #%d (%s -> %s).',
            $message->id,
            $thread->id,
            $from,
            $line->phone_number
        ));

        return self::SUCCESS;
    }

    private function resolveLine(string $value): ?SmsLine
    {
        if (ctype_digit($value)) {
            $line = SmsLine::find((int) $value);

            if ($line !== null) {
                return $line;
            }
        }

        return SmsLine::findByNumber($value);
    }
}
