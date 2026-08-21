<?php

namespace Visnsstudio\VisnsPackages\Contracts;

/**
 * Turns whatever the user typed into the login box into the record the OTP is
 * stored against.
 *
 * The package needs the returned record to be an Eloquent model carrying the
 * columns named in config('visns-packages.otp.columns') and an `id` the user
 * table's foreign key points at. Everything else - which fields are searched,
 * how a mobile number is normalised, which duplicates win - is the resolver's
 * business.
 *
 * @see \Visnsstudio\VisnsPackages\Otp\DefaultOtpContactResolver
 */
interface OtpContactResolver
{
    /**
     * @param  string  $contact  The raw contact string, untrimmed.
     * @return object|null       The contact record, or null when nothing matched.
     */
    public function __invoke(string $contact): ?object;

    /**
     * Which field of the resolved record the given input matched, in the
     * vocabulary the application's OtpSender understands.
     *
     * Called only when __invoke() returned a record.
     */
    public function matchedMethod(object $contact, string $input): string;

    /**
     * The masked form of the contact detail the code was sent to, for the
     * "we sent a code to jo***@example.com" line. Never return the full value.
     */
    public function maskedContact(object $contact, string $method): string;
}
