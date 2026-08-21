<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Messaging;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Visnsstudio\VisnsPackages\Models\SmsLine;
use Visnsstudio\VisnsPackages\Models\SmsThread;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * Shared harness for the messaging suite: the module turned on, its six tables
 * built from the shipped migrations, and a way to mint staff, lines and threads.
 *
 * The migrations are run rather than restated because they ARE the schema this
 * module ships - a test against a hand-written table would prove nothing about
 * what an application actually gets.
 */
abstract class MessagingTestCase extends TestCase
{
    protected const BASE = '/ajax/sms';

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('visns-packages.messaging.enabled', true);

        // The default in config, restated here so a test reading this file knows
        // which transport it is running against without chasing the default.
        $app['config']->set('visns-packages.messaging.transport', 'null');
    }

    protected function defineDatabaseMigrations()
    {
        parent::defineDatabaseMigrations();

        foreach ([
            '2026_08_21_120000_create_sms_lines_table.php',
            '2026_08_21_120010_create_sms_line_user_table.php',
            '2026_08_21_120020_create_sms_threads_table.php',
            '2026_08_21_120030_create_sms_messages_table.php',
            '2026_08_21_120040_create_sms_thread_reads_table.php',
            '2026_08_21_120050_create_sms_templates_table.php',
        ] as $migration) {
            $this->runPackageMigration($migration);
        }
    }

    protected function staffWith(string ...$permissions): User
    {
        static $seq = 0;
        $seq++;

        $user = User::create([
            'name' => 'Staff ' . $seq,
            'firstname' => 'Staff',
            'surname' => (string) $seq,
            'email' => 'staff' . $seq . '@example.test',
            'password' => Hash::make('correct-horse'),
        ]);

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
            $user->givePermissionTo($name);
        }

        return $user;
    }

    /** Somebody who may use messaging but administers nothing. */
    protected function member(): User
    {
        return $this->staffWith('Messaging Access');
    }

    /** Somebody holding the administrative grant as well. */
    protected function admin(): User
    {
        return $this->staffWith('Messaging Access', 'Messaging Manage');
    }

    /**
     * @param  array<int, User>  $users  Staff to attach to the line.
     */
    protected function line(array $users = [], array $attributes = []): SmsLine
    {
        static $seq = 0;
        $seq++;

        $line = SmsLine::create(array_merge([
            'label' => 'Reception ' . $seq,
            'phone_number' => '+6189375254' . ($seq % 10),
            'active' => true,
        ], $attributes));

        foreach ($users as $user) {
            $line->users()->attach($user->id, ['notify' => true]);
        }

        return $line;
    }

    protected function thread(SmsLine $line, string $number = '+61412345678', array $attributes = []): SmsThread
    {
        return SmsThread::create(array_merge([
            'line_id' => $line->id,
            'external_number' => $number,
        ], $attributes));
    }
}
