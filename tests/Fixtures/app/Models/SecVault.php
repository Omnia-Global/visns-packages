<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Visnsstudio\VisnsPackages\Traits\HasRelationshipSorting;

/**
 * Test-only dynamic entity for the 4.17.4 hardening tests: a hidden column
 * (`pin`), a column whose name says it is a secret (`api_token`), a field the
 * model excludes from responses (`internal_code`), and a relation to a user
 * whose `password` is hidden.
 */
class SecVault extends Model
{
    use HasRelationshipSorting;

    protected $table = 'sec_vaults';

    protected $fillable = ['name', 'user_id', 'internal_code'];

    protected $hidden = ['pin'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function excludedFields(): array
    {
        return ['internal_code'];
    }

    public function validationRules($context = 'store', $requestData = null)
    {
        return [];
    }
}
