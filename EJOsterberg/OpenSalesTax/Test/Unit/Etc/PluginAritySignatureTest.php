<?php
// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Test\Unit\Etc;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use SimpleXMLElement;

/**
 * Regression test for **Bug D** (latent v0.1.0 â†’ v1.3.2) and the broader
 * class of plugin/target-method arity mismatches.
 *
 * Magento's compiled Interceptor pattern uses the plugin method's
 * signature to decide what to forward to the parent. If the plugin
 * declares fewer parameters than the target method, the parent is
 * called with too few args at runtime â†’ ArgumentCountError. This is
 * **invisible at `setup:di:compile` time** (compiler doesn't check arity
 * across plugin and target) and invisible to unit tests that instantiate
 * the plugin directly with mocks.
 *
 * For every `<type>` + `<plugin>` pair in `etc/di.xml`, this test:
 *  1. Reads the plugin class's `before*`, `after*`, `around*` methods.
 *  2. Resolves each to the matching public method on the target class
 *     (e.g. `beforeCollect` â†’ `collect` on the target).
 *  3. Asserts the plugin method's parameter count matches the target's:
 *      - `before<X>(subject, ...args)` must take 1 + N where N is the
 *        target method's arg count.
 *      - `after<X>(subject, result, ...args)` must take 2 + N.
 *      - `around<X>(subject, proceed, ...args)` must take 2 + N.
 *
 * The target-class method introspection requires the target class to
 * be loadable. We piggy-back on the `KNOWN_MAGENTO_CLASSES` allowlist
 * mechanism from `DiXmlTargetClassTest` â€” for each entry, the test
 * ALSO needs a stub or shim that exposes the method signature. Where
 * that's not available, the test records a SKIP with a captured note.
 *
 * Bug D was: `QuoteTotalsTaxPlugin::beforeCollect` declared
 * `($subject, $shippingAssignment, $total)` (1+2 args). Target
 * `Magento\Tax\Model\Sales\Total\Quote\Tax::collect` takes
 * `(Quote, ShippingAssignment, Total)` (3 args), so the plugin should
 * have been `($subject, $quote, $shippingAssignment, $total)` (1+3).
 * Verified to fail on the buggy 1+2 signature.
 */
final class PluginAritySignatureTest extends TestCase
{
    /**
     * Target class â†’ method-name â†’ expected arg count. Maintained
     * by hand because the real Magento classes aren't available
     * during unit tests; each entry must be verified against a
     * `vendor/magento/...` source file.
     *
     * @var array<string, array<string, int>>
     */
    private const TARGET_METHOD_ARITIES = [
        // vendor/magento/module-tax/Model/Calculation.php â€” getRate(DataObject $request) â†’ 1 arg
        'Magento\Tax\Model\Calculation' => [
            'getRate' => 1,
        ],
        // vendor/magento/module-tax/Model/Sales/Total/Quote/Tax.php â€” collect(Quote, ShippingAssignment, Total) â†’ 3 args
        'Magento\Tax\Model\Sales\Total\Quote\Tax' => [
            'collect' => 3,
        ],
    ];

    public function testEveryPluginMethodMatchesTargetArity(): void
    {
        $diXmlPath = __DIR__ . '/../../../etc/di.xml';
        self::assertFileExists($diXmlPath);

        $xml = simplexml_load_file($diXmlPath);
        self::assertInstanceOf(SimpleXMLElement::class, $xml);

        $checked = 0;
        $offenders = [];
        $skipped = [];

        foreach ($xml->type as $type) {
            $targetClass = (string)$type['name'];
            if ($targetClass === '') {
                continue;
            }

            foreach ($type->plugin as $plugin) {
                $pluginClass = (string)$plugin['type'];
                if ($pluginClass === '' || !class_exists($pluginClass)) {
                    continue;
                }

                $targetArities = self::TARGET_METHOD_ARITIES[$targetClass] ?? null;
                if ($targetArities === null) {
                    $skipped[] = sprintf(
                        '%s (no TARGET_METHOD_ARITIES entry; add one with the verifying vendor/magento/... path)',
                        $targetClass
                    );
                    continue;
                }

                $reflection = new ReflectionClass($pluginClass);
                foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                    $methodName = $method->getName();
                    if (
                        !str_starts_with($methodName, 'before')
                        && !str_starts_with($methodName, 'after')
                        && !str_starts_with($methodName, 'around')
                    ) {
                        continue;
                    }

                    [$prefix, $targetMethod] = self::splitPluginMethodName($methodName);
                    if ($targetMethod === null) {
                        continue;
                    }

                    $targetArity = $targetArities[$targetMethod] ?? null;
                    if ($targetArity === null) {
                        $skipped[] = sprintf(
                            '%s::%s (no arity recorded for target method "%s")',
                            $pluginClass,
                            $methodName,
                            $targetMethod
                        );
                        continue;
                    }

                    // Magento contract:
                    //   before*: subject + N target args
                    //   after*:  subject + result + N target args
                    //   around*: subject + proceed + N target args
                    $expectedPrefixArgs = $prefix === 'before' ? 1 : 2;
                    $expectedTotal = $expectedPrefixArgs + $targetArity;

                    $actualTotal = $method->getNumberOfParameters();
                    $checked++;

                    if ($actualTotal !== $expectedTotal) {
                        $offenders[] = sprintf(
                            '%s::%s â€” declared %d params, target %s::%s expects %d (so plugin needs %d = %d %s + %d target args). Bug D class.',
                            $pluginClass,
                            $methodName,
                            $actualTotal,
                            $targetClass,
                            $targetMethod,
                            $targetArity,
                            $expectedTotal,
                            $expectedPrefixArgs,
                            $prefix === 'before' ? '($subject)' : '($subject + $result/$proceed)',
                            $targetArity
                        );
                    }
                }
            }
        }

        self::assertGreaterThan(
            0,
            $checked,
            'No plugin/target arities were checked â€” TARGET_METHOD_ARITIES is empty or di.xml has no plugins.'
        );

        self::assertSame(
            [],
            $offenders,
            "Plugin/target arity mismatches detected (Bug D class). Magento Interceptors call the parent\n"
            . "with the plugin's declared arg count â€” too few = ArgumentCountError at runtime, invisible\n"
            . "at `setup:di:compile` time and invisible to unit tests that bypass DI. Offenders:\n  - "
            . implode("\n  - ", $offenders)
            . ($skipped !== [] ? "\n\nAlso skipped (consider adding to TARGET_METHOD_ARITIES):\n  - " . implode("\n  - ", $skipped) : '')
        );

        // Don't fail the test for skips â€” they're a "TODO add coverage" signal,
        // not a regression. But surface them in the test name so they're
        // visible in CI output.
        if ($skipped !== []) {
            self::addWarning(
                'PluginAritySignatureTest skipped some checks (no TARGET_METHOD_ARITIES entries):'
                . "\n  - " . implode("\n  - ", $skipped)
            );
        }
    }

    /**
     * Split a plugin method name into its prefix + target method name.
     * E.g. `beforeCollect` â†’ ['before', 'collect'].
     *
     * @return array{0: string, 1: string|null}
     */
    private static function splitPluginMethodName(string $name): array
    {
        foreach (['before', 'after', 'around'] as $prefix) {
            if (str_starts_with($name, $prefix) && strlen($name) > strlen($prefix)) {
                $remainder = substr($name, strlen($prefix));
                $target = lcfirst($remainder);
                return [$prefix, $target];
            }
        }
        return ['', null];
    }
}
