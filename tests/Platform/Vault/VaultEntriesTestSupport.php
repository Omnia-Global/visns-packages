<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Vault;

use Illuminate\Support\Facades\DB;
use Visnsstudio\VisnsPackages\Models\VaultEntry;

/**
 * Helpers the entry tests need that are about reaching round the model rather
 * than about the vault itself.
 */
abstract class VaultEntriesTestSupport extends VaultTestCase
{
    /**
     * The row exactly as it sits on disk, with no casts applied - the only way
     * to prove a column is genuinely encrypted rather than merely hidden.
     */
    protected function rawRow(int $id): object
    {
        return DB::table((new VaultEntry())->getTable())->where('id', $id)->first();
    }
}
