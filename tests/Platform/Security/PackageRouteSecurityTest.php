<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Security;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Visnsstudio\VisnsPackages\Controllers\DynamicController;
use Visnsstudio\VisnsPackages\Controllers\PDFController;
use Visnsstudio\VisnsPackages\Tests\TestCase;
use Visnsstudio\VisnsPackages\VisnsPackagesServiceProvider;

/**
 * 4.17.0: the package's own routes no longer depend on each application
 * remembering to put `auth` in `routes_middleware`.
 */
class PackageRouteSecurityTest extends TestCase
{
    private function user(string $email = 'dana@example.test'): User
    {
        return User::create(['name' => 'Dana', 'email' => $email, 'password' => Hash::make('secret-password')]);
    }

    #[Test]
    public function a_guest_is_refused_on_the_file_role_permission_pdf_and_notification_routes(): void
    {
        foreach ([
            ['GET', '/ajax/files/1'],
            ['POST', '/ajax/files/downloadByPath'],
            ['GET', '/ajax/roles'],
            ['GET', '/ajax/permissions'],
            ['POST', '/ajax/pdf/generate-from-html'],
            ['POST', '/ajax/user/notifications'],
            ['GET', '/ajax/user/two-factor-auth'],
        ] as [$method, $uri]) {
            $this->json($method, $uri)->assertStatus(401);
        }
    }

    #[Test]
    public function the_profile_still_answers_a_guest_the_way_the_sign_in_screen_expects(): void
    {
        $this->getJson('/ajax/user/profile')->assertOk();
    }

    #[Test]
    public function self_registration_is_off_unless_an_application_turns_it_on(): void
    {
        $this->postJson('/register', [])->assertNotFound();
        $this->postJson('/api/register', [])->assertNotFound();

        config(['visns-packages.auth.registration_enabled' => true]);
        $this->postJson('/api/register', [])->assertStatus(422); // reaches validation
    }

    #[Test]
    public function sign_in_is_throttled_per_address_and_one_address_does_not_lock_out_another(): void
    {
        $this->user();

        for ($i = 0; $i < 10; $i++) {
            $this->assertNotSame(429, $this->postJson('/login/authenticate', ['email' => 'dana@example.test', 'password' => 'wrong'])->getStatusCode());
        }
        $this->postJson('/login/authenticate', ['email' => 'dana@example.test', 'password' => 'wrong'])->assertStatus(429);
        $this->assertNotSame(429, $this->postJson('/login/authenticate', ['email' => 'kim@example.test', 'password' => 'wrong'])->getStatusCode());
    }

    #[Test]
    public function download_by_path_reaches_a_known_file_row_only_and_always_as_an_attachment(): void
    {
        Schema::create('files', function (Blueprint $t) {
            $t->id();
            $t->string('file_path');
            $t->string('file_name')->nullable();
            $t->timestamps();
        });
        Storage::fake(config('filesystems.default'));
        Storage::put('uploads/report.pdf', '%PDF-1.4 report');
        Storage::put('private/other.pdf', '%PDF-1.4 somebody else');
        \Illuminate\Support\Facades\DB::table('files')->insert(['file_path' => 'uploads/report.pdf', 'file_name' => 'report.pdf']);

        $this->actingAs($this->user());
        $ask = fn ($path, $name = 'report.pdf') => $this->postJson('/ajax/files/downloadByPath', [
            'file_name' => $name, 'file_path' => $path, 'file_extension' => 'x.pdf',
        ]);

        $ok = $ask('uploads/report.pdf', "evil\"\r\nX-Injected: 1.pdf");
        $ok->assertOk();
        $this->assertStringStartsWith('attachment;', $ok->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString("\n", $ok->headers->get('Content-Disposition'));
        $this->assertNull($ok->headers->get('X-Injected'));

        $ask('private/other.pdf')->assertNotFound();      // on disk, not a file row
        $ask('uploads/../private/other.pdf')->assertNotFound();
        $ask('/uploads/../../.env')->assertNotFound();
    }

    #[Test]
    public function every_pdf_render_has_php_off_whatever_the_call_site_or_caller_asked_for(): void
    {
        $controller = (new \ReflectionClass(PDFController::class))->newInstanceWithoutConstructor();
        $hardened = (new \ReflectionMethod($controller, 'hardened'))->invoke($controller, [
            'isPhpEnabled' => true, 'isJavascriptEnabled' => true, 'debugKeepTemp' => true, 'isRemoteEnabled' => true,
        ]);

        $this->assertFalse($hardened['isPhpEnabled']);
        $this->assertFalse($hardened['enable_php']);
        $this->assertFalse($hardened['isJavascriptEnabled']);
        $this->assertFalse($hardened['debugKeepTemp']);
        $this->assertSame(public_path(), $hardened['chroot']);
        $this->assertTrue($hardened['isRemoteEnabled'], 'remote stays on by default for S3 images');

        config(['visns-packages.pdf.remote_enabled' => false]);
        $this->assertFalse((new \ReflectionMethod($controller, 'hardened'))->invoke($controller, ['isRemoteEnabled' => true])['isRemoteEnabled']);

        $callerKeys = (new \ReflectionClassConstant(PDFController::class, 'CALLER_OPTIONS'))->getValue();
        foreach (['isPhpEnabled', 'chroot', 'isRemoteEnabled', 'enable_remote'] as $key) {
            $this->assertNotContains($key, $callerKeys);
        }
    }

    #[Test]
    public function a_record_can_copy_only_a_fresh_upload_key(): void
    {
        $controller = (new \ReflectionClass(DynamicController::class))->newInstanceWithoutConstructor();
        $isUpload = new \ReflectionMethod($controller, 'isUploadKey');

        $this->assertTrue($isUpload->invoke($controller, 'tmp/2f1c5a4e-uuid'));
        foreach (['customers/12/contract.pdf', 'tmp/../customers/12/contract.pdf', '/etc/passwd', null, ['tmp/x']] as $key) {
            $this->assertFalse($isUpload->invoke($controller, $key), var_export($key, true));
        }
    }

    #[Test]
    public function an_entity_with_no_middleware_of_its_own_takes_the_configured_default(): void
    {
        $provider = $this->app->getProvider(VisnsPackagesServiceProvider::class);
        $resolve = new \ReflectionMethod($provider, 'entityMiddleware');

        $this->assertSame(['web', 'auth'], $resolve->invoke($provider, 'widgets', ['web']));

        config(['visns-packages.entity_default_middleware' => ['auth', 'permission:Admin']]);
        $this->assertSame(['web', 'auth', 'permission:Admin'], $resolve->invoke($provider, 'widgets', ['web']));

        config(['visns-packages.entity_config.widgets.middleware' => ['auth']]);
        $this->assertSame(['web', 'auth'], $resolve->invoke($provider, 'widgets', ['web']));
    }
}
