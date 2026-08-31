<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;
use Visnsstudio\VisnsPackages\Support\PhoneNumber;

/**
 * One conversation between a line and an outside number.
 *
 * The row carries a denormalised summary of its own newest message so that the
 * thread list - by far the most-loaded screen here - never touches the messages
 * table. `touchLastMessage()` is the only thing allowed to write those columns,
 * so there is exactly one place where the list can go stale.
 */
class SmsThread extends Model
{
    protected $guarded = [];

    protected $casts = [
        'last_message_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function getTable()
    {
        return ModuleConfig::get('messaging.tables.threads', 'sms_threads');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function line()
    {
        return $this->belongsTo(SmsLine::class, 'line_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function messages()
    {
        return $this->hasMany(SmsMessage::class, 'thread_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function reads()
    {
        return $this->hasMany(SmsThreadRead::class, 'thread_id');
    }

    public function getDisplayNumberAttribute(): string
    {
        return PhoneNumber::toLocal(
            $this->external_number,
            (string) ModuleConfig::get('messaging.default_country', 'AU')
        );
    }

    /**
     * Threads on lines this user may work. Mirrors SmsLine::scopeVisibleTo so
     * the two can never disagree about who sees what.
     */
    public function scopeVisibleTo(Builder $query, $user, bool $manages = false): Builder
    {
        if ($manages) {
            return $query;
        }

        $id = is_object($user) ? ($user->id ?? null) : $user;

        if ($id === null) {
            return $query->whereRaw('1 = 0');
        }

        $pivot = ModuleConfig::get('messaging.tables.line_user', 'sms_line_user');

        return $query->whereExists(function ($q) use ($pivot, $id) {
            $q->selectRaw('1')
                ->from($pivot)
                ->whereColumn($pivot . '.line_id', $this->getTable() . '.line_id')
                ->where($pivot . '.user_id', $id);
        });
    }

    /**
     * A LIKE across the fields a person would type into the thread search:
     * whatever the contact is called, whoever the CRM matched, the number
     * itself, and the last thing said.
     *
     * The number is searched by its DIGITS as well as verbatim, because a user
     * searching "0412 345" is typing the local spelling of a number stored as
     * +61412345678 - without that, searching for a number you can see on screen
     * would find nothing.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%' . $term . '%';
        $digits = PhoneNumber::digits($term);

        return $query->where(function (Builder $q) use ($like, $digits, $term) {
            $q->where('contact_name', 'like', $like)
                ->orWhere('client_name', 'like', $like)
                ->orWhere('external_number', 'like', $like)
                ->orWhere('last_message_preview', 'like', $like);

            if ($digits !== '') {
                // "0412345678" and "412345678" both have to find
                // "+61412345678", so match on the trailing digits.
                $q->orWhere('external_number', 'like', '%' . ltrim($digits, '0') . '%');
            }

            // A term that is itself a number in some other spelling.
            $e164 = PhoneNumber::toE164($term, (string) ModuleConfig::get(
                'messaging.default_country',
                'AU'
            ));

            if ($e164 !== null) {
                $q->orWhere('external_number', $e164);
            }
        });
    }

    /**
     * Find the conversation for a line and an outside address, creating it the
     * first time.
     *
     * The uniqueness of (line_id, external_number) is enforced in the schema as
     * well; firstOrCreate on the same pair is what makes an inbound webhook and
     * a staff member starting a conversation land on one row rather than two.
     *
     * `$address` is either an E.164 number or a sender ID (`Apple`, `ANZ`, a
     * short code) - see PhoneNumber::toSenderId. Two things follow from the
     * second case:
     *
     * **The lookup is case-insensitive for a sender ID.** The address is stored
     * exactly as the carrier sent it, because that string is what the thread is
     * called on screen; but `Apple` and `APPLE` are one conversation. MySQL's
     * default collation would fold them anyway - SQLite's `=` would not - so
     * the fold is written out rather than inherited from whichever database the
     * application happens to run on. Without it the test suite and production
     * would disagree about how many threads exist.
     *
     * **The client resolver is not called for one.** It is the application's
     * number -> client hook and a sender ID has no digits to match; handing it
     * `Apple` asks a host application's code a question it was never written to
     * answer, for a lookup that cannot succeed.
     *
     * `$resolver` is called only when the thread is created: re-resolving on
     * every message would put an application query on the webhook's hot path,
     * and a client whose record changes later is relinked from the UI.
     */
    public static function findOrCreateFor(SmsLine $line, string $address, ?callable $resolver = null): self
    {
        $isSenderId = PhoneNumber::isSenderId($address);

        $thread = static::query()
            ->where('line_id', $line->id)
            ->when(
                $isSenderId,
                fn (Builder $q) => $q->whereRaw('UPPER(external_number) = ?', [mb_strtoupper($address)]),
                fn (Builder $q) => $q->where('external_number', $address)
            )
            ->first();

        if ($thread !== null) {
            return $thread;
        }

        $client = null;

        if ($resolver !== null && ! $isSenderId) {
            try {
                $resolved = $resolver($address);
                $client = is_array($resolved) ? $resolved : null;
            } catch (\Throwable $e) {
                // Enrichment is a nicety - a throwing hook must not cost the
                // practice an inbound message.
                \Illuminate\Support\Facades\Log::warning('sms.client resolver failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return static::create([
            'line_id' => $line->id,
            'external_number' => $address,
            'client_id' => $client['id'] ?? null,
            'client_name' => $client['name'] ?? null,
        ]);
    }

    /**
     * Re-stamp the denormalised summary from a message. The ONLY writer of
     * those four columns.
     */
    public function touchLastMessage(SmsMessage $message): void
    {
        $at = $message->received_at ?? $message->sent_at ?? $message->created_at ?? now();

        $this->forceFill([
            'last_message_at' => $at,
            // 191 is the column; a long SMS is truncated for the list and read
            // in full from the thread.
            'last_message_preview' => mb_substr((string) $message->body, 0, 180),
            'last_direction' => $message->direction,
            'last_message_status' => $message->status,
        ])->save();
    }

    /**
     * Unread counts for many threads at once, keyed by thread id.
     *
     * The thread list needs one of these per row, and doing it per row is the
     * N+1 that would make a busy inbox unusable. One grouped query with a LEFT
     * JOIN onto the read marks answers all of them: a thread with no read row
     * joins to nulls and every inbound message counts, which is exactly the
     * "never opened" rule.
     *
     * @param  array<int, int>|null  $threadIds  Restrict to these threads.
     * @param  array<int, int>|null  $lineIds    Restrict to threads on these lines.
     * @return array<int, int>
     */
    public static function unreadCountsFor($user, ?array $threadIds = null, ?array $lineIds = null): array
    {
        $id = is_object($user) ? ($user->id ?? null) : $user;

        if ($id === null) {
            return [];
        }

        $messages = (string) ModuleConfig::get('messaging.tables.messages', 'sms_messages');
        $reads = (string) ModuleConfig::get('messaging.tables.thread_reads', 'sms_thread_reads');
        $threads = (string) ModuleConfig::get('messaging.tables.threads', 'sms_threads');

        $query = \Illuminate\Support\Facades\DB::table($messages)
            ->leftJoin($reads, function ($join) use ($reads, $messages, $id) {
                $join->on($reads . '.thread_id', '=', $messages . '.thread_id')
                    ->where($reads . '.user_id', '=', $id);
            })
            ->where($messages . '.direction', self::inboundDirection())
            ->where(function ($q) use ($reads, $messages) {
                $q->whereNull($reads . '.last_read_message_id')
                    ->orWhereColumn($messages . '.id', '>', $reads . '.last_read_message_id');
            });

        if ($threadIds !== null) {
            if ($threadIds === []) {
                return [];
            }

            $query->whereIn($messages . '.thread_id', $threadIds);
        }

        if ($lineIds !== null) {
            if ($lineIds === []) {
                return [];
            }

            $query->whereIn($messages . '.thread_id', function ($q) use ($threads, $lineIds) {
                $q->select('id')->from($threads)->whereIn('line_id', $lineIds);
            });
        }

        return $query
            ->groupBy($messages . '.thread_id')
            ->selectRaw($messages . '.thread_id as thread_id, COUNT(*) as unread')
            ->pluck('unread', 'thread_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * Named so the direction literal is not repeated inside a raw-ish query.
     */
    private static function inboundDirection(): string
    {
        return SmsMessage::DIRECTION_IN;
    }

    /**
     * How many inbound messages in this thread are newer than the given user's
     * read mark. No read row at all means "never opened" - everything counts.
     */
    public function unreadCountFor($user): int
    {
        $id = is_object($user) ? ($user->id ?? null) : $user;

        if ($id === null) {
            return 0;
        }

        $read = SmsThreadRead::query()
            ->where('thread_id', $this->id)
            ->where('user_id', $id)
            ->first();

        $query = $this->messages()->where('direction', 'in');

        if ($read !== null && $read->last_read_message_id !== null) {
            $query->where('id', '>', $read->last_read_message_id);
        }

        return (int) $query->count();
    }
}
