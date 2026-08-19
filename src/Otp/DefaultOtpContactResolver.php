<?php

namespace Visnsstudio\VisnsPackages\Otp;

use Illuminate\Support\Facades\Schema;

use Visnsstudio\VisnsPackages\Contracts\OtpContactResolver;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * The bundled contact resolver: a deliberately plain search of the OTP user
 * table on email, then username, then mobile.
 *
 * It is a sane default, not a general solution. An application whose contacts
 * live somewhere richer - the Throughlife CRM searches company_contact across
 * two email columns, a username and a normalised mobile, prefers active
 * contacts, and breaks duplicate ties towards client companies - registers its
 * own resolver in config('visns-packages.otp.contact_resolver') and this class
 * is never built.
 */
class DefaultOtpContactResolver implements OtpContactResolver
{
    /**
     * Columns searched, in priority order.
     *
     * @var array<int, string>
     */
    protected array $searchColumns = ['email', 'username', 'mobile'];

    /**
     * Column listings, keyed by table.
     *
     * Which of the three columns exist is settled once per process with a
     * single introspection query rather than by rescuing a QueryException per
     * column: a failed query can abort a surrounding transaction on some
     * drivers, and swallowing QueryException would also hide real database
     * faults behind a "contact not found".
     *
     * @var array<string, array<int, string>>
     */
    protected static array $columnCache = [];

    /**
     * @return object|null The user record the code will be stored against.
     */
    public function __invoke(string $contact): ?object
    {
        $contact = trim($contact);

        if ($contact === '') {
            return null;
        }

        $columns = $this->availableColumns();

        if ($columns === []) {
            return null;
        }

        // Priority order, one query per column: the first column that matches
        // wins, so an address used as somebody's username cannot displace the
        // account that actually owns the email.
        foreach ($columns as $column) {
            $record = ModuleConfig::userQuery('otp')
                ->where($column, $contact)
                ->first();

            if ($record) {
                return $record;
            }
        }

        return null;
    }

    /**
     * Which field the input matched, in the vocabulary an OtpSender reads.
     */
    public function matchedMethod(object $contact, string $input): string
    {
        $input = trim($input);

        foreach ($this->availableColumns() as $column) {
            if ((string) ($contact->{$column} ?? '') === $input && $input !== '') {
                return $column;
            }
        }

        // Fall back to email, matching the bundled search order: a record was
        // found, so something matched, and email is the channel every
        // application has.
        return 'email';
    }

    /**
     * The masked form shown back to the caller.
     *
     * Never the full value: this response is unauthenticated, so anyone who
     * can guess a contact detail would otherwise be handed the rest of it.
     */
    public function maskedContact(object $contact, string $method): string
    {
        switch ($method) {
            case 'email':
                return $this->maskEmail((string) ($contact->email ?? ''));

            case 'mobile':
                return $this->maskMobile((string) ($contact->mobile ?? ''));

            case 'username':
                // A username is not itself a channel, so show whichever real
                // channel the code went out on.
                $mobile = (string) ($contact->mobile ?? '');

                if ($mobile !== '') {
                    return $this->maskMobile($mobile);
                }

                return $this->maskEmail((string) ($contact->email ?? ''));

            default:
                return '***';
        }
    }

    /**
     * jo***@example.com, or jo*** when there is no domain to keep.
     */
    protected function maskEmail(string $email): string
    {
        if ($email === '') {
            return '***';
        }

        if (strpos($email, '@') !== false) {
            $parts = explode('@', $email);

            return substr($parts[0], 0, 2) . '***@' . $parts[1];
        }

        return substr($email, 0, 2) . '***';
    }

    /**
     * 0412***89 - enough for the owner to recognise their own number, not
     * enough for anyone else to dial it.
     */
    protected function maskMobile(string $mobile): string
    {
        if ($mobile === '') {
            return '***';
        }

        return substr($mobile, 0, 4) . '***' . substr($mobile, -2);
    }

    /**
     * The searchable columns that actually exist on the user table.
     *
     * @return array<int, string>
     */
    protected function availableColumns(): array
    {
        $model = ModuleConfig::userModel('otp');
        $instance = new $model();
        $table = $instance->getTable();
        $connection = $instance->getConnectionName();
        $cacheKey = ($connection ?? 'default') . ':' . $table;

        if (! array_key_exists($cacheKey, static::$columnCache)) {
            try {
                static::$columnCache[$cacheKey] = Schema::connection($connection)
                    ->getColumnListing($table);
            } catch (\Throwable $e) {
                static::$columnCache[$cacheKey] = [];
            }
        }

        return array_values(array_intersect(
            $this->searchColumns,
            static::$columnCache[$cacheKey]
        ));
    }
}
