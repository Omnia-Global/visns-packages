<?php

/**
 * Unit coverage for the semantic model registry itself: normalisation,
 * validation and the client-safe projection.
 *
 * Nothing here needs a booted application - the model is a pure array
 * transformation - so the test stays self-contained, the same way the Wave-1
 * formula validation test does.
 */

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Visnsstudio\VisnsPackages\Services\ReportSemantics\SemanticException;
use Visnsstudio\VisnsPackages\Services\ReportSemantics\SemanticModel;

class ReportSemanticModelTest extends TestCase
{
    /**
     * A small but representative registry.
     *
     * @return array
     */
    private function registry(): array
    {
        return [
            'clients' => [
                'label' => 'Client',
                'plural' => 'Clients',
                'description' => 'People the practice advises',
                'table' => 'clients',
                'fields' => [
                    'firstname' => [
                        'label' => 'First name',
                        'column' => 'firstname',
                        'type' => 'text',
                    ],
                    'fee_amount' => [
                        'label' => 'Fee amount',
                        'column' => 'fee_amount',
                        'type' => 'money',
                        'summable' => true,
                    ],
                    'home_suburb' => [
                        'label' => 'Home suburb',
                        'json' => [
                            'column' => 'home_address',
                            'path' => '$.suburb',
                        ],
                        'type' => 'text',
                    ],
                    'status' => [
                        'label' => 'Status',
                        'column' => 'status_id',
                        'type' => 'enum',
                        // Ascending 0/1 keys: these must survive as an object
                        // in JSON, not collapse into a list.
                        'values' => [0 => 'Inactive', 1 => 'Active'],
                    ],
                    'internal_score' => [
                        'label' => 'Internal score',
                        'type' => 'number',
                        'hidden' => true,
                    ],
                ],
                'relations' => [
                    'adviser' => [
                        'label' => 'Their adviser',
                        'entity' => 'users',
                        'type' => 'belongs_to',
                        'foreign_key' => 'user_id',
                        'owner_key' => 'id',
                        'zero_is_null' => true,
                    ],
                    'notes' => [
                        'label' => 'Their notes',
                        'entity' => 'client_notes',
                        'type' => 'has_many',
                        'foreign_key' => 'client_id',
                        'local_key' => 'id',
                    ],
                ],
            ],
            'users' => [
                'label' => 'Adviser',
                'table' => 'users',
                'fields' => [
                    'name' => ['label' => 'Name', 'type' => 'text'],
                ],
            ],
            'client_notes' => [
                'label' => 'Note',
                'table' => 'client_notes',
                'fields' => [
                    'body' => ['label' => 'Body', 'type' => 'text'],
                ],
            ],
        ];
    }

    /** @test */
    public function it_never_exposes_tables_columns_or_join_keys()
    {
        $payload = (new SemanticModel($this->registry()))->toClientPayload();
        $encoded = json_encode($payload);

        foreach (
            [
                'table',
                'column',
                'json',
                'foreign_key',
                'owner_key',
                'local_key',
                'primary_key',
                'status_id',
                'home_address',
                'user_id',
                'client_id',
            ]
            as $leak
        ) {
            $this->assertStringNotContainsString(
                $leak,
                $encoded,
                "Internal key [{$leak}] leaked into the client payload"
            );
        }
    }

    /** @test */
    public function it_projects_the_shape_the_wizard_expects()
    {
        $payload = (new SemanticModel($this->registry()))->toClientPayload();
        $clients = $payload['entities']['clients'];

        $this->assertSame('Client', $clients['label']);
        $this->assertSame('Clients', $clients['plural']);
        $this->assertSame(
            'People the practice advises',
            $clients['description']
        );

        $this->assertSame(
            ['label' => 'First name', 'type' => 'text'],
            $clients['fields']['firstname']
        );

        $this->assertSame(
            [
                'label' => 'Fee amount',
                'type' => 'money',
                'summable' => true,
            ],
            $clients['fields']['fee_amount']
        );

        $this->assertSame(
            [
                'label' => 'Their adviser',
                'entity' => 'users',
                'cardinality' => 'one',
            ],
            $clients['relations']['adviser']
        );

        $this->assertSame(
            'many',
            $clients['relations']['notes']['cardinality']
        );
    }

    /** @test */
    public function it_keeps_enum_values_keyed_as_an_object()
    {
        $payload = (new SemanticModel($this->registry()))->toClientPayload();

        // The wire format is what matters: an ascending 0/1 array would
        // serialise as ["Inactive","Active"] and lose the stored values.
        $this->assertStringContainsString(
            '"values":{"0":"Inactive","1":"Active"}',
            json_encode($payload)
        );

        $decoded = json_decode(json_encode($payload), true);

        $this->assertSame(
            ['0' => 'Inactive', '1' => 'Active'],
            $decoded['entities']['clients']['fields']['status']['values']
        );
    }

    /** @test */
    public function it_hides_fields_marked_hidden_but_still_resolves_them()
    {
        $model = new SemanticModel($this->registry());
        $payload = $model->toClientPayload();

        $this->assertArrayNotHasKey(
            'internal_score',
            $payload['entities']['clients']['fields']
        );

        $field = $model->field('clients', 'internal_score');
        $this->assertSame('number', $field['type']);
    }

    /** @test */
    public function it_publishes_a_hidden_entity_flagged_rather_than_dropping_it()
    {
        $registry = $this->registry();
        $registry['client_notes']['hidden'] = true;

        $model = new SemanticModel($registry);
        $payload = $model->toClientPayload();

        // A lookup stays in the payload, flagged, so the wizard can leave it
        // out of the root picker on its own...
        $this->assertArrayHasKey('client_notes', $payload['entities']);
        $this->assertTrue($payload['entities']['client_notes']['hidden']);

        // ...and an ordinary entity is not flagged at all.
        $this->assertArrayNotHasKey('hidden', $payload['entities']['clients']);

        // Dropping the entity instead would silently sever this relation and
        // make `notes.body` unreachable from the wizard.
        $this->assertArrayHasKey(
            'notes',
            $payload['entities']['clients']['relations']
        );
        $this->assertSame(
            'client_notes',
            $payload['entities']['clients']['relations']['notes']['entity']
        );
    }

    /** @test */
    public function it_applies_documented_defaults()
    {
        $model = new SemanticModel([
            'users' => [
                'fields' => [
                    'first_name' => [],
                ],
                'relations' => [],
            ],
        ]);

        $entity = $model->entity('users');
        $field = $model->field('users', 'first_name');

        // table defaults to the entity id; label to the humanised id
        $this->assertSame('users', $entity['table']);
        $this->assertSame('Users', $entity['label']);
        $this->assertSame('id', $entity['primary_key']);

        // column defaults to the field id, type to text, summable to false
        $this->assertSame('first_name', $field['column']);
        $this->assertSame('text', $field['type']);
        $this->assertSame('First name', $field['label']);
        $this->assertFalse($field['summable']);
    }

    /** @test */
    public function it_reports_unknown_entities_fields_and_relations()
    {
        $model = new SemanticModel($this->registry());

        try {
            $model->field('clients', 'nope', 'nope');
            $this->fail('Expected an unknown field to be rejected');
        } catch (SemanticException $e) {
            $this->assertSame(422, $e->status());
            $this->assertSame('nope', $e->errors()[0]['path']);
        }

        $this->expectException(SemanticException::class);
        $model->relation('clients', 'spouse', 'spouse.name');
    }

    /** @test */
    public function it_rejects_a_registry_that_names_an_unsafe_identifier()
    {
        $this->expectException(RuntimeException::class);

        new SemanticModel([
            'clients' => [
                'table' => 'clients; drop table users',
                'fields' => ['id' => []],
            ],
        ]);
    }

    /** @test */
    public function it_rejects_an_unsafe_json_path()
    {
        $this->expectException(RuntimeException::class);

        new SemanticModel([
            'clients' => [
                'fields' => [
                    'suburb' => [
                        'json' => [
                            'column' => 'home_address',
                            'path' => "$.a') or 1=1 -- ",
                        ],
                    ],
                ],
            ],
        ]);
    }

    /** @test */
    public function it_rejects_a_relation_pointing_at_an_unpublished_entity()
    {
        $this->expectException(RuntimeException::class);

        new SemanticModel([
            'clients' => [
                'fields' => ['id' => []],
                'relations' => [
                    'adviser' => [
                        'entity' => 'users',
                        'foreign_key' => 'user_id',
                    ],
                ],
            ],
        ]);
    }

    /** @test */
    public function it_rejects_unknown_field_types_and_relation_types()
    {
        $threw = 0;

        foreach (
            [
                ['clients' => ['fields' => ['a' => ['type' => 'currency']]]],
                [
                    'clients' => [
                        'fields' => ['a' => []],
                        'relations' => [
                            'x' => [
                                'entity' => 'clients',
                                'type' => 'morph_to',
                                'foreign_key' => 'x_id',
                            ],
                        ],
                    ],
                ],
                // A relation with no foreign key cannot be joined.
                [
                    'clients' => [
                        'fields' => ['a' => []],
                        'relations' => [
                            'x' => ['entity' => 'clients'],
                        ],
                    ],
                ],
            ]
            as $registry
        ) {
            try {
                new SemanticModel($registry);
            } catch (RuntimeException $e) {
                $threw++;
            }
        }

        $this->assertSame(3, $threw);
    }

    /** @test */
    public function it_merges_a_registrar_over_the_config_entities()
    {
        $model = SemanticModel::fromConfig([
            'entities' => [
                'users' => [
                    'label' => 'From config',
                    'fields' => ['name' => []],
                ],
            ],
            'registrar' => function () {
                return [
                    'users' => [
                        'label' => 'From registrar',
                        'fields' => ['name' => [], 'email' => []],
                    ],
                ];
            },
        ]);

        $this->assertSame('From registrar', $model->entity('users')['label']);
        $this->assertArrayHasKey('email', $model->entity('users')['fields']);
    }

    /** @test */
    public function it_publishes_the_operator_allowlist_the_contract_promises()
    {
        $this->assertSame(
            [
                'equals',
                'not_equals',
                'contains',
                'not_contains',
                'is_empty',
                'not_empty',
            ],
            SemanticModel::operatorsFor('text')
        );

        $this->assertSame(
            ['is_true', 'is_false'],
            SemanticModel::operatorsFor('boolean')
        );

        $this->assertSame(
            ['equals', 'not_equals', 'in', 'not_in'],
            SemanticModel::operatorsFor('enum')
        );

        $this->assertSame([], SemanticModel::operatorsFor('nonsense'));
    }
}
