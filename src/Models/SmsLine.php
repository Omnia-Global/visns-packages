<?php

namespace Visnsstudio\VisnsPackages\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;
use Visnsstudio\VisnsPackages\Support\PhoneNumber;

/**
 * One SMS-capable number the organisation sends and receives on.
 *
 * The pivot to users is this module's entire visibility model, so it lives on
 * the model as a scope (`visibleTo`) rather than being restated in each
 * controller method - every read path goes through it and a miss is a 404.
 */
class SmsLine extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'active' => 'boolean',
        'settings' => 'array',
    ];

    /**
     * Resolved here rather than in the constructor so static query builders see
     * the configured name too.
     */
    public function getTable()
    {
        return ModuleConfig::get('messaging.tables.lines', 'sms_lines');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function users()
    {
        return $this->belongsToMany(
            ModuleConfig::userModel('messaging'),
            ModuleConfig::get('messaging.tables.line_user', 'sms_line_user'),
            'line_id',
            'user_id'
        )->withPivot('notify')->withTimestamps();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function threads()
    {
        return $this->hasMany(SmsThread::class, 'line_id');
    }

    /**
     * The lines this user may work.
     *
     * Attached staff see their own lines; a holder of the manage permission sees
     * every line, because somebody has to be able to set them up and to answer
     * the number nobody is attached to.
     */
    public function scopeVisibleTo(Builder $query, $user, bool $manages = false): Builder
    {
        if ($manages) {
            return $query;
        }

        $id = is_object($user) ? ($user->id ?? null) : $user;

        if ($id === null) {
            // Not a user at all: sees nothing. whereRaw(0) rather than an empty
            // whereIn so the intent survives a later refactor.
            return $query->whereRaw('1 = 0');
        }

        $pivot = ModuleConfig::get('messaging.tables.line_user', 'sms_line_user');

        return $query->whereExists(function ($q) use ($pivot, $id) {
            $q->selectRaw('1')
                ->from($pivot)
                ->whereColumn($pivot . '.line_id', $this->getTable() . '.id')
                ->where($pivot . '.user_id', $id);
        });
    }

    /**
     * The number as a human reads it ("0412 345 678"), computed rather than
     * stored so a change of formatting never needs a data migration.
     */
    public function getDisplayNumberAttribute(): string
    {
        return PhoneNumber::toLocal(
            $this->phone_number,
            (string) ModuleConfig::get('messaging.default_country', 'AU')
        );
    }

    /**
     * The line one E.164 number belongs to, or null.
     *
     * The single join between "what Zoom sent" and "which inbox this is", used
     * by the webhook and by every send.
     */
    public static function findByNumber(?string $number): ?self
    {
        $country = (string) ModuleConfig::get('messaging.default_country', 'AU');
        $e164 = PhoneNumber::toE164((string) $number, $country);

        if ($e164 === null) {
            return null;
        }

        return static::query()->where('phone_number', $e164)->first();
    }
}
