<?php

use Illuminate\Database\Schema\Blueprint;
use Laragear\WebAuthn\Models\WebAuthnCredential;

/**
 * Passkeys (WebAuthn credentials).
 *
 * Published, like every migration in this package, rather than loaded: the
 * table belongs to the application, and an application that never enables
 * `visns-packages.passkeys` has no reason to carry it.
 *
 * The column list is laragear/webauthn's own - see
 * WebAuthnCredential::migration() - because the package's model, query scopes
 * and ceremony pipes all read those names. Restating them here would mean
 * maintaining a copy that has to stay in step with the library.
 *
 * `->with()` is the library's hook for the consumer's own columns.
 */
return WebAuthnCredential::migration()->with(function (Blueprint $table): void {
    // The library tracks `counter` and `updated_at`, but neither answers the
    // question the management screen actually asks: "is this the key on the
    // laptop I still use?". Stamped by the CredentialAsserted listener the
    // package service provider registers while the module is enabled.
    $table->timestamp('last_used_at')->nullable();
});
