<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canned SMS bodies ("Your review is booked for ...").
 *
 * Deliberately dumb: a name, a body and a sort order. No placeholder engine -
 * the composer drops the text into the box and the staff member edits it, which
 * is what happens to a templated SMS anyway.
 *
 * Soft deletes because a retired template is worth being able to bring back, and
 * because a hard delete from an admin screen is the kind of mistake that gets
 * noticed a week later.
 *
 * Idempotent: an application that already owns this table is left as it is.
 */
return new class extends Migration {
    public function up(): void
    {
        $table = $this->table();

        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $t) {
            $t->id();

            $t->string('name', 191);
            $t->text('body');
            $t->boolean('active')->default(true)->index();
            $t->integer('sort')->default(0)->index();

            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (string) config(
            'visns-packages.messaging.tables.templates',
            'sms_templates'
        );
    }
};
