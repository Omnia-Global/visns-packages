<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\ReportSemantics;

use PHPUnit\Framework\TestCase;
use Visnsstudio\VisnsPackages\Services\ReportSemantics\LegacyDefinitionConverter;
use Visnsstudio\VisnsPackages\Services\ReportSemantics\SemanticModel;

/**
 * The v1 -> v2 report definition converter. Pure: no application, no
 * database - a small inline registry shaped like Lifespan's.
 */
class LegacyDefinitionConverterTest extends TestCase
{
    private function model(): SemanticModel
    {
        return new SemanticModel([
            'clients' => [
                'table' => 'clients',
                'fields' => [
                    'firstname' => ['label' => 'First name', 'type' => 'text'],
                    'surname' => ['label' => 'Surname', 'type' => 'text'],
                    'preferred_name' => ['label' => 'Preferred name', 'type' => 'text'],
                    'home_email' => ['label' => 'Personal email', 'type' => 'text'],
                    'work_email' => ['label' => 'Work email', 'type' => 'text'],
                    'home_phone' => ['label' => 'Home phone', 'type' => 'text'],
                    'work_phone' => ['label' => 'Work phone', 'type' => 'text'],
                    'dob' => ['label' => 'Date of birth', 'type' => 'date'],
                    'fee_amount' => ['label' => 'Fee', 'type' => 'money'],
                    'fee_agreement' => ['label' => 'Fee agreement', 'type' => 'boolean'],
                    'status' => [
                        'label' => 'Status',
                        'column' => 'status_id',
                        'type' => 'enum',
                        'values' => [1 => 'Active', 0 => 'Inactive'],
                    ],
                    'home_suburb' => ['label' => 'Home suburb', 'type' => 'text', 'json' => ['column' => 'home_address', 'path' => '$.suburb']],
                    'home_state' => ['label' => 'Home state', 'type' => 'text', 'json' => ['column' => 'home_address', 'path' => '$.state']],
                    'home_postcode' => ['label' => 'Home postcode', 'type' => 'text', 'json' => ['column' => 'home_address', 'path' => '$.postcode']],
                    'mail_suburb' => ['label' => 'Postal suburb', 'type' => 'text', 'json' => ['column' => 'mail_address', 'path' => '$.suburb']],
                    'mail_state' => ['label' => 'Postal state', 'type' => 'text', 'json' => ['column' => 'mail_address', 'path' => '$.state']],
                    'mail_postcode' => ['label' => 'Postal postcode', 'type' => 'text', 'json' => ['column' => 'mail_address', 'path' => '$.postcode']],
                ],
                'relations' => [
                    'adviser' => [
                        'entity' => 'users',
                        'type' => 'belongs_to',
                        'foreign_key' => 'user_id',
                        'owner_key' => 'id',
                    ],
                    'notes' => [
                        'entity' => 'client_notes',
                        'type' => 'has_many',
                        'foreign_key' => 'client_id',
                        'local_key' => 'id',
                    ],
                ],
            ],
            'users' => [
                'table' => 'users',
                'fields' => [
                    'name' => ['label' => 'Name', 'type' => 'text'],
                ],
            ],
            'client_notes' => [
                'table' => 'client_notes',
                'hidden' => true,
                'fields' => [
                    'subject' => ['label' => 'Subject', 'type' => 'text'],
                ],
            ],
        ]);
    }

    private function convert(array $v1)
    {
        return (new LegacyDefinitionConverter())->convert($v1, $this->model());
    }

    private function notesContain($result, string $needle): bool
    {
        foreach ($result->notes as $note) {
            if (str_contains($note, $needle)) {
                return true;
            }
        }

        return false;
    }

    /* ---- the three Lifespan shapes ---------------------------------- */

    public function test_client_test_report_shape(): void
    {
        $result = $this->convert([
            'joins' => [],
            'columns' => [
                ['alias' => 'Clients.Firstname', 'table' => 'clients', 'column' => 'firstname'],
                ['alias' => 'Clients.Surname', 'table' => 'clients', 'column' => 'surname'],
                ['alias' => 'Clients.Dob', 'table' => 'clients', 'column' => 'dob'],
            ],
            'filters' => [
                'groups' => [[
                    'filters' => [['table' => 'clients', 'value' => 'ize', 'column' => 'firstname', 'operator' => 'LIKE']],
                    'operator' => 'AND',
                ]],
                'operator' => 'AND',
            ],
            'sorting' => [['table' => 'clients', 'column' => 'surname', 'direction' => 'ASC']],
            'mainTable' => 'clients',
        ]);

        $this->assertTrue($result->isConvertible());
        $this->assertSame([
            'schema_version' => 2,
            'entity' => 'clients',
            'fields' => [['field' => 'firstname'], ['field' => 'surname'], ['field' => 'dob']],
            'filters' => ['op' => 'and', 'items' => [
                ['field' => 'firstname', 'operator' => 'contains', 'value' => 'ize'],
            ]],
            'sort' => [['field' => 'surname', 'dir' => 'asc']],
        ], $result->definition);
        $this->assertSame([], $result->notes);
    }

    public function test_dated_clients_report_shape_with_nonsense_manual_join(): void
    {
        $result = $this->convert([
            'joins' => [[
                'isManual' => true,
                'joinType' => 'FULL JOIN',
                'sourceTable' => 'clients',
                'targetTable' => 'users',
                'sourceColumn' => 'mobile',
                'targetColumn' => 'name',
            ]],
            'columns' => [
                ['table' => 'clients', 'column' => 'id', 'displayName' => 'Id'],
                ['table' => 'clients', 'column' => 'created_at', 'displayName' => 'Created At'],
                ['table' => 'clients', 'column' => 'firstname', 'displayName' => 'Firstname'],
                ['table' => 'clients', 'column' => 'surname', 'displayName' => 'Surname'],
                ['table' => 'clients', 'column' => 'other_phone', 'displayName' => 'Other Phone'],
                ['table' => 'clients', 'column' => 'work_phone_preferred', 'displayName' => 'Work Phone Preferred'],
                ['table' => 'clients', 'column' => 'work_email', 'displayName' => 'Work Email'],
                ['table' => 'clients', 'column' => 'home_address', 'displayName' => 'Home Address'],
            ],
            'filters' => [
                'groups' => [
                    ['filters' => [], 'operator' => 'AND'],
                    ['filters' => [], 'operator' => 'OR'],
                ],
                'operator' => 'OR',
            ],
            'sorting' => [],
            'mainTable' => 'clients',
        ]);

        $this->assertTrue($result->isConvertible());
        $this->assertSame([
            ['field' => 'firstname'],
            ['field' => 'surname'],
            ['field' => 'work_email'],
            ['field' => 'home_suburb'],
            ['field' => 'home_state'],
            ['field' => 'home_postcode'],
        ], $result->definition['fields']);
        $this->assertArrayNotHasKey('filters', $result->definition);
        $this->assertArrayNotHasKey('sort', $result->definition);
        $this->assertTrue($this->notesContain($result, 'clients.mobile = users.name'));
        $this->assertTrue($this->notesContain($result, '[clients.id]'));
        $this->assertTrue($this->notesContain($result, '[clients.created_at]'));
        $this->assertTrue($this->notesContain($result, '[clients.work_phone_preferred]'));
        $this->assertFalse($this->notesContain($result, 'OR'), 'empty OR groups are not worth a note');
    }

    public function test_katanning_shape_fans_json_filters_out_into_or_groups(): void
    {
        $result = $this->convert([
            'joins' => [],
            'columns' => [
                ['table' => 'clients', 'column' => 'surname'],
                ['table' => 'clients', 'column' => 'home_address'],
                ['table' => 'clients', 'column' => 'mail_address'],
                ['table' => 'clients', 'column' => 'other_phone'],
            ],
            'filters' => [
                'groups' => [[
                    'filters' => [
                        ['table' => 'clients', 'value' => 'Katanning', 'column' => 'home_address', 'operator' => 'LIKE'],
                        ['table' => 'clients', 'value' => 'Katan', 'column' => 'mail_address', 'operator' => 'LIKE'],
                    ],
                    'operator' => 'AND',
                ]],
                'operator' => 'AND',
            ],
            'sorting' => [],
            'mainTable' => 'clients',
        ]);

        $this->assertTrue($result->isConvertible());
        $this->assertSame(
            ['surname', 'home_suburb', 'home_state', 'home_postcode', 'mail_suburb', 'mail_state', 'mail_postcode'],
            array_column($result->definition['fields'], 'field')
        );

        $contains = fn ($field, $value) => ['field' => $field, 'operator' => 'contains', 'value' => $value];

        $this->assertSame(['op' => 'and', 'items' => [
            ['op' => 'or', 'items' => [
                $contains('home_suburb', 'Katanning'),
                $contains('home_state', 'Katanning'),
                $contains('home_postcode', 'Katanning'),
            ]],
            ['op' => 'or', 'items' => [
                $contains('mail_suburb', 'Katan'),
                $contains('mail_state', 'Katan'),
                $contains('mail_postcode', 'Katan'),
            ]],
        ]], $result->definition['filters']);
        $this->assertTrue($this->notesContain($result, '[clients.other_phone]'));
        $this->assertTrue($this->notesContain($result, 'clients.home_address LIKE'));
    }

    /* ---- individual rules ------------------------------------------- */

    public function test_unmapped_column_filter_and_sort_are_dropped_with_notes(): void
    {
        $result = $this->convert([
            'mainTable' => 'clients',
            'columns' => [['table' => 'clients', 'column' => 'firstname'], ['table' => 'clients', 'column' => 'secret']],
            'filters' => ['operator' => 'AND', 'groups' => [['operator' => 'AND', 'filters' => [
                ['table' => 'clients', 'column' => 'secret', 'operator' => '=', 'value' => 'x'],
            ]]]],
            'sorting' => [['table' => 'clients', 'column' => 'secret', 'direction' => 'DESC']],
        ]);

        $this->assertSame([['field' => 'firstname']], $result->definition['fields']);
        $this->assertArrayNotHasKey('filters', $result->definition);
        $this->assertArrayNotHasKey('sort', $result->definition);
        $this->assertCount(3, $result->notes);
    }

    public function test_json_column_fans_out_to_every_published_part_in_order(): void
    {
        $result = $this->convert([
            'mainTable' => 'clients',
            'columns' => [['column' => 'mail_address']],
            'sorting' => [['column' => 'mail_address', 'direction' => 'desc']],
        ]);

        $this->assertSame(
            [['field' => 'mail_suburb'], ['field' => 'mail_state'], ['field' => 'mail_postcode']],
            $result->definition['fields']
        );
        $this->assertSame(
            [['field' => 'mail_suburb', 'dir' => 'desc'], ['field' => 'mail_state', 'dir' => 'desc'], ['field' => 'mail_postcode', 'dir' => 'desc']],
            $result->definition['sort']
        );
        $this->assertTrue($this->notesContain($result, 'Postal suburb, Postal state, Postal postcode'));
    }

    public function test_negated_json_filter_fans_out_with_and(): void
    {
        $result = $this->convert([
            'mainTable' => 'clients',
            'columns' => [['column' => 'firstname']],
            'filters' => [['column' => 'home_address', 'operator' => 'IS NULL']],
        ]);

        $this->assertSame(['op' => 'and', 'items' => [
            ['field' => 'home_suburb', 'operator' => 'is_empty'],
            ['field' => 'home_state', 'operator' => 'is_empty'],
            ['field' => 'home_postcode', 'operator' => 'is_empty'],
        ]], $result->definition['filters']);
    }

    public function test_manual_join_matching_no_relation_is_dropped_with_its_columns(): void
    {
        $result = $this->convert([
            'mainTable' => 'clients',
            'joins' => [[
                'isManual' => true,
                'joinType' => 'INNER JOIN',
                'sourceTable' => 'clients',
                'targetTable' => 'users',
                'sourceColumn' => 'mobile',
                'targetColumn' => 'name',
            ]],
            'columns' => [['table' => 'clients', 'column' => 'firstname'], ['table' => 'users', 'column' => 'name']],
            'sorting' => [['table' => 'users', 'column' => 'name', 'direction' => 'asc']],
        ]);

        $this->assertSame([['field' => 'firstname']], $result->definition['fields']);
        $this->assertArrayNotHasKey('sort', $result->definition);
        $this->assertTrue($this->notesContain($result, 'no relation in the semantic model matches it'));
        $this->assertTrue($this->notesContain($result, '[users] is not joined'));
    }

    public function test_join_matching_a_relation_becomes_a_path(): void
    {
        $result = $this->convert([
            'mainTable' => 'clients',
            'joins' => [
                // Written from the target's side, and an inner join.
                ['sourceTable' => 'users', 'targetTable' => 'clients', 'sourceColumn' => 'id', 'targetColumn' => 'user_id', 'joinType' => 'INNER JOIN'],
                ['sourceTable' => 'clients', 'targetTable' => 'client_notes', 'sourceColumn' => 'id', 'targetColumn' => 'client_id', 'joinType' => 'LEFT JOIN'],
            ],
            'columns' => [
                ['table' => 'clients', 'column' => 'firstname'],
                ['table' => 'users', 'column' => 'name'],
                ['table' => 'client_notes', 'column' => 'subject'],
            ],
            'filters' => [['table' => 'users', 'column' => 'name', 'operator' => '=', 'value' => 'Ada']],
        ]);

        $this->assertSame(
            ['firstname', 'adviser.name', 'notes.subject'],
            array_column($result->definition['fields'], 'field')
        );
        $this->assertSame(
            ['op' => 'and', 'items' => [['field' => 'adviser.name', 'operator' => 'equals', 'value' => 'Ada']]],
            $result->definition['filters']
        );
        $this->assertTrue($this->notesContain($result, 'always LEFT JOINs'));
    }

    public function test_in_maps_to_in_on_enum_and_to_or_equals_on_text(): void
    {
        $result = $this->convert([
            'mainTable' => 'clients',
            'columns' => [['column' => 'firstname']],
            'filters' => ['operator' => 'AND', 'groups' => [['operator' => 'AND', 'filters' => [
                ['column' => 'status_id', 'operator' => 'IN', 'value' => ['1', '0']],
                ['column' => 'surname', 'operator' => 'in', 'value' => 'Smith, Jones'],
                ['column' => 'status_id', 'operator' => 'NOT IN', 'value' => '0'],
            ]]]],
        ]);

        $this->assertSame(['op' => 'and', 'items' => [
            ['field' => 'status', 'operator' => 'in', 'value' => ['1', '0']],
            ['op' => 'or', 'items' => [
                ['field' => 'surname', 'operator' => 'equals', 'value' => 'Smith'],
                ['field' => 'surname', 'operator' => 'equals', 'value' => 'Jones'],
            ]],
            ['field' => 'status', 'operator' => 'not_in', 'value' => ['0']],
        ]], $result->definition['filters']);
    }

    public function test_like_strips_surrounding_percent_signs(): void
    {
        $result = $this->convert([
            'mainTable' => 'clients',
            'columns' => [['column' => 'firstname']],
            'filters' => [
                ['column' => 'firstname', 'operator' => 'LIKE', 'value' => '%ann%'],
                ['column' => 'surname', 'operator' => 'NOT LIKE', 'value' => '%%smith'],
                ['column' => 'work_email', 'operator' => 'LIKE', 'value' => '%'],
            ],
        ]);

        $this->assertSame(['op' => 'and', 'items' => [
            ['field' => 'firstname', 'operator' => 'contains', 'value' => 'ann'],
            ['field' => 'surname', 'operator' => 'not_contains', 'value' => 'smith'],
        ]], $result->definition['filters']);
        $this->assertTrue($this->notesContain($result, 'matches everything'));
    }

    public function test_comparison_and_null_operators_follow_the_field_type(): void
    {
        $result = $this->convert([
            'mainTable' => 'clients',
            'columns' => [['column' => 'firstname']],
            'filters' => [
                ['column' => 'fee_amount', 'operator' => '>=', 'value' => '100'],
                ['column' => 'fee_amount', 'operator' => '<', 'value' => '500'],
                ['column' => 'dob', 'operator' => '>', 'value' => '1980-01-01'],
                ['column' => 'dob', 'operator' => '<=', 'value' => '1990-12-31'],
                ['column' => 'dob', 'operator' => 'IS NOT NULL'],
                ['column' => 'firstname', 'operator' => '>', 'value' => 'M'],
                ['column' => 'work_email', 'operator' => '!=', 'value' => 'x@y.z'],
                ['column' => 'fee_agreement', 'operator' => '=', 'value' => '1'],
                ['column' => 'fee_amount', 'operator' => 'BETWEEN', 'value' => ['1', '9']],
                ['column' => 'firstname', 'operator' => 'REGEXP', 'value' => '^A'],
            ],
        ]);

        $this->assertSame(['op' => 'and', 'items' => [
            ['field' => 'fee_amount', 'operator' => 'gte', 'value' => '100'],
            ['field' => 'fee_amount', 'operator' => 'lt', 'value' => '500'],
            ['field' => 'dob', 'operator' => 'after', 'value' => '1980-01-01'],
            ['field' => 'dob', 'operator' => 'before', 'value' => '1991-01-01'],
            ['field' => 'dob', 'operator' => 'not_empty'],
            ['field' => 'work_email', 'operator' => 'not_equals', 'value' => 'x@y.z'],
            ['field' => 'fee_agreement', 'operator' => 'is_true'],
            ['field' => 'fee_amount', 'operator' => 'between', 'value' => ['1', '9']],
        ]], $result->definition['filters']);
        $this->assertTrue($this->notesContain($result, 'comparisons are only available'));
        $this->assertTrue($this->notesContain($result, 'operator [REGEXP]'));
    }

    public function test_or_groups_and_nesting_are_preserved_and_flagged(): void
    {
        $result = $this->convert([
            'mainTable' => 'clients',
            'columns' => [['column' => 'firstname']],
            'filters' => ['operator' => 'OR', 'groups' => [
                ['operator' => 'AND', 'filters' => [
                    ['column' => 'firstname', 'operator' => '=', 'value' => 'Ann'],
                    ['column' => 'surname', 'operator' => '=', 'value' => 'Lee'],
                ]],
                ['operator' => 'AND', 'filters' => [
                    ['column' => 'home_email', 'operator' => 'IS NULL'],
                ]],
            ]],
        ]);

        $this->assertSame(['op' => 'or', 'items' => [
            ['op' => 'and', 'items' => [
                ['field' => 'firstname', 'operator' => 'equals', 'value' => 'Ann'],
                ['field' => 'surname', 'operator' => 'equals', 'value' => 'Lee'],
            ]],
            ['field' => 'home_email', 'operator' => 'is_empty'],
        ]], $result->definition['filters']);
        $this->assertTrue($this->notesContain($result, 'combine conditions with OR'));
    }

    public function test_unknown_main_table_is_not_convertible(): void
    {
        $result = $this->convert([
            'mainTable' => 'widgets',
            'columns' => [['table' => 'widgets', 'column' => 'name']],
        ]);

        $this->assertFalse($result->isConvertible());
        $this->assertNull($result->definition);
        $this->assertStringContainsString('[widgets]', $result->reason);
    }

    public function test_hidden_entity_is_used_with_a_note(): void
    {
        $result = $this->convert([
            'mainTable' => 'client_notes',
            'columns' => [['column' => 'subject']],
        ]);

        $this->assertSame('client_notes', $result->definition['entity']);
        $this->assertTrue($this->notesContain($result, 'hidden lookup'));
    }

    public function test_no_mappable_column_is_not_convertible(): void
    {
        $result = $this->convert([
            'mainTable' => 'clients',
            'columns' => [['column' => 'id'], ['column' => 'created_at']],
        ]);

        $this->assertFalse($result->isConvertible());
        $this->assertCount(2, $result->notes);
    }

    public function test_legacy_detection(): void
    {
        $this->assertTrue(LegacyDefinitionConverter::isLegacyDefinition(['mainTable' => 'clients']));
        $this->assertFalse(LegacyDefinitionConverter::isLegacyDefinition(['schema_version' => 2, 'entity' => 'clients']));
        $this->assertFalse(LegacyDefinitionConverter::isLegacyDefinition(['mainTable' => 'clients', 'schema_version' => 2]));
        $this->assertFalse(LegacyDefinitionConverter::isLegacyDefinition('nope'));
    }
}
