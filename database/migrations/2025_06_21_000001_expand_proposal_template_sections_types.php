<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ExpandProposalTemplateSectionsTypes extends Migration
{
    /** Every section type ProposalAssemblyService can emit. */
    protected const TYPES = [
        'cover_page',
        'toc',
        'content',
        'quote_items',
        'terms',
        'terms_conditions',
        'review_log',
        'overview',
        'acceptance',
        'payment_terms',
        'agreement_signature',
    ];

    /** The five the column was originally created with. */
    protected const ORIGINAL_TYPES = [
        'cover_page',
        'toc',
        'content',
        'quote_items',
        'terms',
    ];

    /**
     * Widen `section_type` to every type the assembly service emits.
     *
     * This was a bare `ALTER TABLE ... MODIFY COLUMN ... ENUM(...)`, which is
     * MySQL-only syntax — so the whole migration set was unrunnable on SQLite,
     * and a consuming application could not use an in-memory database for its
     * tests.
     *
     * It is not merely cosmetic there either: Laravel renders `enum` on SQLite
     * as a varchar carrying a CHECK constraint, so skipping this migration
     * leaves a database that silently rejects 'overview', 'acceptance' and the
     * rest — the proposal system half-works in a way that reads as an
     * application bug.
     */
    public function up()
    {
        $this->setTypes(self::TYPES);
    }

    /**
     * WARNING: reverting fails on rows already using a new section type, which
     * is the correct outcome — the alternative is dropping their data.
     */
    public function down()
    {
        $this->setTypes(self::ORIGINAL_TYPES);
    }

    protected function setTypes(array $types): void
    {
        if (!Schema::hasTable('proposal_template_sections')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            $list = implode(', ', array_map(fn($t) => "'{$t}'", $types));

            DB::statement(
                "ALTER TABLE proposal_template_sections MODIFY COLUMN section_type ENUM({$list})"
            );

            return;
        }

        // Everywhere else the column is a varchar carrying a CHECK constraint —
        // that is how Laravel renders `enum` outside MySQL. It cannot be
        // widened in place, and SQLite will not even drop a column a CHECK
        // still references, so the column becomes a plain string instead:
        // Laravel rebuilds the table and the constraint goes with it. Nothing
        // is lost — the legal set is enforced by the application, and on a
        // local or test database a constraint that silently rejects half of
        // them is worse than none.
        Schema::table('proposal_template_sections', function (Blueprint $table) {
            $table->string('section_type')->nullable()->change();
        });
    }
}
