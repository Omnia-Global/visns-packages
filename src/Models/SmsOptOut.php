<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Model;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;
use Visnsstudio\VisnsPackages\Support\PhoneNumber;

/**
 * A number that has asked not to be texted.
 *
 * One row per handset, keyed on the number itself - see the migration for why
 * it is not per line and not per campaign. Everything that decides whether a
 * number is on this list goes through Services\Sms\SmsOptOuts rather than
 * querying here, so the normalisation rule has one home.
 *
 * NOT soft-deleted, and that is the one unusual thing about it. Everywhere else
 * in this module a delete is a soft delete, because a client communication has
 * to be producible years later. An opt-out is the opposite kind of record: it
 * exists to be CONSULTED, and a soft-deleted row that a badly written scope
 * still matched would be a text to somebody who asked us to stop. Opting back
 * in removes the row; the `vault`-style "who did what" question is answered by
 * the application's own auditing if it wants it.
 */
class SmsOptOut extends Model
{
    /** The client texted a keyword. */
    public const SOURCE_KEYWORD = 'keyword';

    /** Somebody in the practice recorded a request made another way. */
    public const SOURCE_MANUAL = 'manual';

    protected $guarded = [];

    public function getTable()
    {
        return ModuleConfig::get('messaging.tables.opt_outs', 'sms_opt_outs');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function line()
    {
        return $this->belongsTo(SmsLine::class, 'line_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(ModuleConfig::userModel('messaging'), 'user_id');
    }

    /**
     * The number as a human reads it, computed the same way a line's and a
     * thread's are so one screen cannot spell a number differently from another.
     */
    public function getDisplayNumberAttribute(): string
    {
        return PhoneNumber::toLocal(
            $this->number,
            (string) ModuleConfig::get('messaging.default_country', 'AU')
        );
    }
}
