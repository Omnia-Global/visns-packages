<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Search;

use App\Models\SecClient;
use App\Models\SecOwner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Visnsstudio\VisnsPackages\Support\RecordUrl;
use Visnsstudio\VisnsPackages\Tests\Platform\Vault\VaultTestCase;

/**
 * 4.18.0: the two configured URL patterns - universal search's
 * `sources[].url` and the vault's `client.url` - take a `{slug}` token beside
 * `{id}`, and `{slug}` falls back to the id where a record has no slug.
 */
class RecordUrlTokenTest extends VaultTestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('visns-packages.universal_search.enabled', true);
        $app['config']->set('visns-packages.universal_search.sources', [
            'clients' => [
                'label' => 'Clients',
                'model' => SecClient::class,
                'columns' => ['name'],
                'title' => 'name',
                'url' => '/clients/{slug}',
            ],
            'clientsById' => [
                'label' => 'Clients by id',
                'model' => SecClient::class,
                'columns' => ['name'],
                'title' => 'name',
                'url' => '/legacy/{id}',
            ],
            'owners' => [
                'label' => 'Owners',
                'model' => SecOwner::class,
                'columns' => ['name'],
                'title' => 'name',
                'url' => '/owners/{slug}',
            ],
        ]);
        $app['config']->set('visns-packages.vault.client.model', SecClient::class);
        $app['config']->set('visns-packages.vault.client.url', '/clients/{slug}');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('sec_clients', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->nullable();
            $t->timestamps();
        });
        Schema::create('sec_owners', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->timestamps();
        });
    }

    private function urls(string $term): array
    {
        $groups = $this->actingAs($this->member())
            ->getJson('/ajax/search?q=' . urlencode($term))
            ->assertOk()
            ->json('groups');

        $urls = [];
        foreach ($groups as $group) {
            foreach ($group['results'] ?? $group['items'] ?? [] as $row) {
                $urls[$group['key'] ?? $group['label']][] = $row['url'];
            }
        }

        return $urls;
    }

    #[Test]
    public function the_helper_fills_both_tokens_and_falls_back_to_the_id(): void
    {
        $this->assertSame('/c/acme', RecordUrl::fill('/c/{slug}', 7, 'acme'));
        $this->assertSame('/c/7', RecordUrl::fill('/c/{slug}', 7, ''));
        $this->assertSame('/c/7', RecordUrl::fill('/c/{slug}', 7, null));
        $this->assertSame('/c/7/acme', RecordUrl::fill('/c/{id}/{slug}', 7, 'acme'));
        $this->assertSame('/c/7', RecordUrl::fill('/c/{id}', 7, 'acme'));
        $this->assertNull(RecordUrl::fill(null, 7, 'acme'));
    }

    #[Test]
    public function a_search_hit_links_by_slug_and_falls_back_to_the_id(): void
    {
        $acme = SecClient::create(['name' => 'Acme Widgets', 'slug' => 'acme-widgets']);
        $bare = SecClient::create(['name' => 'Acme Bare', 'slug' => null]);
        $owner = SecOwner::create(['name' => 'Acme Owner']);

        $urls = $this->urls('Acme');

        $this->assertEqualsCanonicalizing(['/clients/acme-widgets', '/clients/' . $bare->id], $urls['clients']);
        // {id} is unchanged even where the row has a slug.
        $this->assertEqualsCanonicalizing(['/legacy/' . $acme->id, '/legacy/' . $bare->id], $urls['clientsById']);
        // A model with no slug attribute at all falls back to the id.
        $this->assertSame(['/owners/' . $owner->id], $urls['owners']);
    }

    #[Test]
    public function the_vault_client_link_uses_the_slug_and_falls_back_to_the_id(): void
    {
        $acme = SecClient::create(['name' => 'Acme Widgets', 'slug' => 'acme-widgets']);
        $bare = SecClient::create(['name' => 'Bare Co', 'slug' => '']);
        $admin = $this->admin();

        $this->entryFor($admin, ['client_id' => $acme->id, 'client_label' => 'Acme Widgets', 'title' => 'One']);
        $this->entryFor($admin, ['client_id' => $bare->id, 'client_label' => 'Bare Co', 'title' => 'Two']);
        $this->entryFor($admin, ['client_id' => 999, 'client_label' => 'Gone', 'title' => 'Three']);

        $rows = collect($this->actingAs($admin)->getJson('/ajax/vault')->assertOk()->json('data'))
            ->keyBy('title');

        $this->assertSame('/clients/acme-widgets', $rows['One']['client_url']);
        $this->assertSame('/clients/' . $bare->id, $rows['Two']['client_url']);
        $this->assertSame('/clients/999', $rows['Three']['client_url']);

        $clients = collect($this->actingAs($admin)->getJson('/ajax/vault/entry-clients')->assertOk()->json('data'))
            ->keyBy('id');

        $this->assertSame('/clients/acme-widgets', $clients[$acme->id]['url']);
        $this->assertSame('/clients/' . $bare->id, $clients[$bare->id]['url']);
    }

    #[Test]
    public function an_id_pattern_on_the_vault_is_unchanged(): void
    {
        config(['visns-packages.vault.client.url' => '/clients/{id}']);
        $acme = SecClient::create(['name' => 'Acme', 'slug' => 'acme']);
        $admin = $this->admin();
        $this->entryFor($admin, ['client_id' => $acme->id, 'client_label' => 'Acme']);

        $row = $this->actingAs($admin)->getJson('/ajax/vault')->assertOk()->json('data.0');

        $this->assertSame('/clients/' . $acme->id, $row['client_url']);
    }
}
