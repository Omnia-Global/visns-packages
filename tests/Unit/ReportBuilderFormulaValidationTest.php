<?php

/**
 * Unit coverage for the report builder's calculated-field validation.
 *
 * The controller extends the host application's base controller and talks to
 * the Log facade, neither of which exists when the package is tested on its
 * own, so both are stubbed here. That keeps the test self-contained - it does
 * not need a booted Laravel application.
 */

namespace App\Http\Controllers {
    if (!class_exists(Controller::class, false)) {
        class Controller
        {
        }
    }
}

namespace Tests\Unit {

    use Illuminate\Container\Container;
    use Illuminate\Support\Facades\Facade;
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;
    use Visnsstudio\VisnsPackages\Controllers\ReportBuilderController;

    class ReportBuilderFormulaValidationTest extends TestCase
    {
        /** @var ReportBuilderController */
        private $controller;

        protected function setUp(): void
        {
            parent::setUp();

            // Minimal container so the Log facade resolves to a sink.
            $container = new Container();
            $container->instance('log', new NullLoggerStub());
            Facade::setFacadeApplication($container);

            $this->controller = new ReportBuilderController();
        }

        protected function tearDown(): void
        {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication(null);

            parent::tearDown();
        }

        /**
         * Call a private method on the controller.
         *
         * @param string $method
         * @param mixed ...$args
         * @return mixed
         */
        private function invoke(string $method, ...$args)
        {
            // No setAccessible() call: it is a no-op since PHP 8.1 and
            // deprecated in 8.5.
            $reflection = new ReflectionMethod(
                ReportBuilderController::class,
                $method
            );

            return $reflection->invokeArgs($this->controller, $args);
        }

        /**
         * @param string $formula
         * @return string|false
         */
        private function validate($formula)
        {
            return $this->invoke('validateCalculatedFieldFormula', $formula);
        }

        /** @test */
        public function it_accepts_a_simple_aggregate_over_a_qualified_column()
        {
            $this->assertSame(
                'SUM(commissions.amount)',
                $this->validate('SUM(commissions.amount)')
            );
        }

        /** @test */
        public function it_accepts_arithmetic_and_nested_allowed_functions()
        {
            $formulas = [
                'SUM(commissions.amount) / COUNT(clients.id)',
                'ROUND(AVG(commissions.amount), 2)',
                'COALESCE(SUM(commissions.amount), 0) * 1.1',
                'DATEDIFF(NOW(), clients.created_at)',
                '(MAX(commissions.amount) - MIN(commissions.amount)) / 2',
                'amount + 10',
            ];

            foreach ($formulas as $formula) {
                $this->assertSame(
                    $formula,
                    $this->validate($formula),
                    "Expected formula to be accepted: {$formula}"
                );
            }
        }

        /** @test */
        public function it_rejects_a_select_subquery_payload()
        {
            $this->assertFalse(
                $this->validate('(SELECT password FROM users LIMIT 1)')
            );
        }

        /** @test */
        public function it_rejects_injection_shaped_formulas()
        {
            $payloads = [
                // Subqueries and set operations
                'SUM(amount) + (SELECT id FROM users)',
                '1 UNION SELECT password FROM users',
                'SUM(amount) FROM users',
                // Statement separators and comments
                'SUM(amount); DROP TABLE users',
                'SUM(amount) -- comment',
                'SUM(amount) /* comment */',
                'SUM(amount) # comment',
                // Quotes of every flavour
                "CONCAT('a','b')",
                'SUM(`users`.`id`)',
                'SUM("a")',
                // Functions outside the allowlist
                'SLEEP(5)',
                'BENCHMARK(1000000, MD5(1))',
                'LOAD_FILE(x)',
                'CONCAT(a, b)',
                // Variables and unsupported operators
                'SUM(amount) = 1',
                'SUM(@@version)',
                'SUM(amount) AND 1',
                // Empty / non-string
                '',
                '   ',
            ];

            foreach ($payloads as $payload) {
                $this->assertFalse(
                    $this->validate($payload),
                    "Expected formula to be rejected: {$payload}"
                );
            }
        }

        /** @test */
        public function it_rejects_non_string_and_oversized_formulas()
        {
            $this->assertFalse($this->validate(null));
            $this->assertFalse($this->validate(['SUM(a.b)']));
            $this->assertFalse(
                $this->validate('SUM(a.b) + ' . str_repeat('1 + ', 300) . '1')
            );
        }

        /** @test */
        public function it_accepts_a_clean_alias_unchanged()
        {
            $this->assertSame(
                'Total Commission_1',
                $this->invoke('sanitizeAlias', '  Total Commission_1  ')
            );
        }

        /** @test */
        public function it_strips_backticks_and_other_unsafe_alias_characters()
        {
            $this->assertSame(
                'Total _ evil',
                $this->invoke('sanitizeAlias', 'Total ` evil')
            );

            $this->assertSame(
                'Amount _',
                $this->invoke('sanitizeAlias', 'Amount ($)')
            );

            // Nothing usable left over
            $this->assertNull($this->invoke('sanitizeAlias', ''));
            $this->assertNull($this->invoke('sanitizeAlias', null));
        }

        /** @test */
        public function it_truncates_long_aliases()
        {
            $alias = $this->invoke('sanitizeAlias', str_repeat('a', 80) . '!');

            $this->assertNotNull($alias);
            $this->assertLessThanOrEqual(64, strlen($alias));
        }
    }

    /**
     * Swallows every log call the controller makes.
     */
    class NullLoggerStub
    {
        /**
         * @param string $method
         * @param array $arguments
         * @return void
         */
        public function __call($method, $arguments)
        {
            // Intentionally empty.
        }
    }
}
