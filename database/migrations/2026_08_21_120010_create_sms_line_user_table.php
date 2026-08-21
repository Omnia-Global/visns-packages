<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which staff work which SMS line.
 *
 * This pivot IS the module's visibility rule: a user sees a line, its threads
 * and its messages when they are attached here, or when they hold the manage
 * permission. Everything else 404s - a thread you are not on the line for does
 * not exist as far as the API is concerned.
 *
 * `notify` is per-attachment rather than per-user: somebody may want the desk
 * number popping at them and the adviser number silent.
 *
 * The user foreign key is added only when the application actually has a
 * `users` table at migration time, and is sized to match `users.id` - legacy
 * schemas carry an `int unsigned` key and MySQL refuses a foreign key between
 * that and a `bigint unsigned` column (errno 150).
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

        $lines = (string) config('visns-packages.messaging.tables.lines', 'sms_lines');

        Schema::create($table, function (Blueprint $t) {
            $t->unsignedBigInteger('line_id')->index();
            $this->userKey($t, 'user_id')->index();

            $t->boolean('notify')->default(true);

            $t->timestamps();

            // One attachment per person per line. The composite is also what
            // the visibility check reads, so it doubles as its index.
            $t->unique(['line_id', 'user_id']);
        });

        if (Schema::hasTable($lines)) {
            Schema::table($table, function (Blueprint $t) use ($lines) {
                // Detaching everyone from a deleted line is the wanted
                // behaviour: the row means nothing without its line.
                $t->foreign('line_id')
                    ->references('id')
                    ->on($lines)
                    ->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table($table, function (Blueprint $t) {
            $t->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (string) config(
            'visns-packages.messaging.tables.line_user',
            'sms_line_user'
        );
    }

    /**
     * A column able to reference users.id whatever width the consumer's users
     * table uses.
     */
    private function userKey(Blueprint $t, string $column): \Illuminate\Database\Schema\ColumnDefinition
    {
        return $this->usersKeyIsBig()
            ? $t->unsignedBigInteger($column)
            : $t->unsignedInteger($column);
    }

    private function usersKeyIsBig(): bool
    {
        if (! Schema::hasTable('users')) {
            return true;
        }

        $type = strtolower((string) Schema::getColumnType('users', 'id'));

        return ! in_array($type, ['int', 'integer', 'int unsigned', 'mediumint', 'smallint'], true);
    }
};
