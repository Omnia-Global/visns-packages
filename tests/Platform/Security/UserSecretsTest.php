<?php

/*
 * owen-it/laravel-auditing is the host application's dependency, not this
 * package's, so its two symbols are stubbed when absent — the pattern
 * ReportBuilderFormulaValidationTest uses for the host's base controller.
 */
namespace OwenIt\Auditing\Contracts {
    if (!interface_exists(Auditable::class)) {
        interface Auditable
        {
        }
    }
}

namespace OwenIt\Auditing {
    if (!trait_exists(Auditable::class)) {
        trait Auditable
        {
        }
    }
}

namespace Visnsstudio\VisnsPackages\Tests\Platform\Security {

use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\Test;
use Visnsstudio\VisnsPackages\Models\User;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/** 4.17.1: the package User never serialises a secret and stores the sign-in tokens encrypted. */
class UserSecretsTest extends TestCase
{
    #[Test]
    public function the_sign_in_tokens_are_encrypted_when_set_and_never_serialised(): void
    {
        $user = new User();
        $user->forceFill([
            'name' => 'Dana',
            'provider_token' => 'ms-access',
            'provider_refresh_token' => 'ms-refresh',
            'two_factor_secret' => 'enc-secret',
        ]);

        $raw = $user->getAttributes();
        $this->assertNotSame('ms-access', $raw['provider_token']);
        $this->assertSame('ms-access', Crypt::decryptString($raw['provider_token']));
        $this->assertSame('ms-refresh', $user->provider_refresh_token);

        $array = $user->toArray();
        foreach (['provider_token', 'provider_refresh_token', 'two_factor_secret', 'api_token'] as $key) {
            $this->assertArrayNotHasKey($key, $array);
        }
        $this->assertSame('Dana', $array['name']);
    }

    #[Test]
    public function a_token_stored_before_the_cast_still_reads(): void
    {
        $user = (new User())->newFromBuilder(['id' => 1, 'provider_token' => 'legacy-plain']);

        $this->assertSame('legacy-plain', $user->provider_token);
    }
}
}
