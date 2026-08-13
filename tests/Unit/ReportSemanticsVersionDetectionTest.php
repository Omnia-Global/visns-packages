<?php

/**
 * Back-compat coverage: a v1 report must keep running down the v1 path.
 *
 * The controller extends the host application's base controller, which does
 * not exist when the package is tested on its own, so it is stubbed here -
 * the same approach the Wave-1 formula validation test takes.
 */

namespace App\Http\Controllers {
    if (!class_exists(Controller::class, false)) {
        class Controller
        {
        }
    }
}

namespace Tests\Unit {

    use Illuminate\Http\Request;
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;
    use Visnsstudio\VisnsPackages\Controllers\ReportBuilderController;
    use Visnsstudio\VisnsPackages\Services\ReportSemantics\QueryCompiler;

    class ReportSemanticsVersionDetectionTest extends TestCase
    {
        /**
         * A representative v1 payload, as the existing builder sends it.
         *
         * @return array
         */
        private function v1Query(): array
        {
            return [
                'mainTable' => 'clients',
                'columns' => [
                    ['table' => 'clients', 'column' => 'firstname'],
                ],
                'joins' => [
                    [
                        'sourceTable' => 'clients',
                        'targetTable' => 'users',
                        'sourceColumn' => 'user_id',
                        'targetColumn' => 'id',
                    ],
                ],
                'filters' => [
                    [
                        'table' => 'clients',
                        'column' => 'status_id',
                        'operator' => '=',
                        'value' => 1,
                    ],
                ],
                'sorting' => [],
                'groupBy' => [],
            ];
        }

        /**
         * @return array
         */
        private function v2Definition(): array
        {
            return [
                'schema_version' => 2,
                'entity' => 'clients',
                'fields' => [['field' => 'firstname']],
            ];
        }

        /** @test */
        public function it_recognises_a_v2_definition()
        {
            $this->assertTrue(
                QueryCompiler::isSemanticDefinition($this->v2Definition())
            );

            // schema_version alone is enough...
            $this->assertTrue(
                QueryCompiler::isSemanticDefinition([
                    'schema_version' => 2,
                    'entity' => 'clients',
                ])
            );

            // ... and so is `entity` on its own, for a client that omits the
            // version.
            $this->assertTrue(
                QueryCompiler::isSemanticDefinition([
                    'entity' => 'clients',
                    'fields' => [],
                ])
            );

            // A future schema version still routes to the semantic path.
            $this->assertTrue(
                QueryCompiler::isSemanticDefinition([
                    'schema_version' => 3,
                    'entity' => 'clients',
                ])
            );
        }

        /** @test */
        public function it_does_not_mistake_a_v1_payload_for_a_definition()
        {
            $this->assertFalse(
                QueryCompiler::isSemanticDefinition($this->v1Query())
            );

            foreach (
                [
                    null,
                    '',
                    'clients',
                    [],
                    ['mainTable' => 'clients'],
                    ['schema_version' => 1, 'mainTable' => 'clients'],
                    // A v1 payload that happens to carry an entity key is
                    // still v1: mainTable is the tell.
                    ['mainTable' => 'clients', 'entity' => 'clients'],
                    ['entity' => ''],
                    ['entity' => ['clients']],
                ]
                as $payload
            ) {
                $this->assertFalse(
                    QueryCompiler::isSemanticDefinition($payload),
                    'Payload was wrongly routed to the semantic compiler: ' .
                        json_encode($payload)
                );
            }
        }

        /**
         * Call the controller's private request sniffer.
         *
         * @param array $input
         * @return array|null
         */
        private function detect(array $input)
        {
            $method = new ReflectionMethod(
                ReportBuilderController::class,
                'semanticDefinitionFrom'
            );

            return $method->invoke(
                new ReportBuilderController(),
                Request::create('/ajax/reportBuilder/execute', 'POST', $input)
            );
        }

        /** @test */
        public function a_v1_execute_request_is_left_to_the_legacy_path()
        {
            // The v1 client posts `query`; nothing about it is a definition,
            // so the controller must hand back null and run the old code.
            $this->assertNull(
                $this->detect([
                    'query' => $this->v1Query(),
                    'limit' => 100,
                    'offset' => 0,
                ])
            );

            // No payload at all is v1's problem to report, not ours.
            $this->assertNull($this->detect([]));
        }

        /** @test */
        public function a_v2_execute_request_is_picked_up_from_either_key()
        {
            $this->assertSame(
                $this->v2Definition(),
                $this->detect(['definition' => $this->v2Definition()])
            );

            // Tolerated: an older client that reuses the v1 `query` key with
            // a v2 body.
            $this->assertSame(
                $this->v2Definition(),
                $this->detect(['query' => $this->v2Definition()])
            );

            // `definition` wins when both are present.
            $detected = $this->detect([
                'definition' => $this->v2Definition(),
                'query' => $this->v1Query(),
            ]);

            $this->assertSame('clients', $detected['entity']);
        }
    }
}
