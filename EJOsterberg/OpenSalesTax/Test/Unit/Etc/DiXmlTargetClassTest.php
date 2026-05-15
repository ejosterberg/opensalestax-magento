<?php
// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Test\Unit\Etc;

use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * Regression test for Bug C (latent v0.1.0 → v1.3.1).
 *
 * `etc/di.xml` registered the totals plugin against
 * `Magento\Quote\Model\Quote\Address\Total\Tax`, a class that does NOT
 * exist in Magento 2.4.7 (the actual collector is
 * `Magento\Tax\Model\Sales\Total\Quote\Tax`). Magento's DI compiler
 * silently no-ops plugins on non-existent target classes, so
 * `setup:di:compile` exited clean and the bug only surfaced under a
 * real `collectTotals()` call — three full minor-version cycles later.
 *
 * This test parses every `<type name="...">` in `di.xml` and asserts
 * the named class either:
 *   - exists in PHP's loaded class table (real Magento class loaded
 *     in a merchant install, OR our PHPStan stub from
 *     `Test/Stubs/MagentoFramework.php`); OR
 *   - is on the project's known-canonical-Magento allowlist below.
 *
 * The allowlist is the explicit safety net for Magento classes that
 * AREN'T stubbed (because we don't need them for unit-test type
 * checking) but ARE real classes verified manually against an actual
 * Magento source tree. Adding to the allowlist requires a comment
 * with the verifying `vendor/magento/...` path.
 */
final class DiXmlTargetClassTest extends TestCase
{
    /**
     * Every Magento class our `di.xml` plugins target. Each entry MUST
     * be verified against a real Magento checkout — the comment names
     * the canonical source path. These are NOT stubbed (no need; the
     * plugin code uses duck-typing on the subjects), so this allowlist
     * IS the safety net.
     */
    private const KNOWN_MAGENTO_CLASSES = [
        // vendor/magento/module-tax/Model/Calculation.php
        'Magento\Tax\Model\Calculation',
        // vendor/magento/module-tax/Model/Sales/Total/Quote/Tax.php
        // Registered in vendor/magento/module-tax/etc/sales.xml as
        // <item name="tax" instance="..." sort_order="450">.
        'Magento\Tax\Model\Sales\Total\Quote\Tax',
    ];

    /**
     * The historic incorrect class name that was in di.xml from v0.1.0
     * through v1.3.1. Belt-and-suspenders: if a future regression
     * accidentally re-introduces it, this test fails loudly.
     */
    private const KNOWN_BAD_CLASSES = [
        // Bug C — does NOT exist in Magento 2.4.7. The Quote module's
        // `Address\Total\Tax` namespace doesn't house a totals collector;
        // the Tax module owns the collector.
        'Magento\Quote\Model\Quote\Address\Total\Tax',
    ];

    public function testDiXmlPluginTargetsResolveToRealMagentoClasses(): void
    {
        $diXmlPath = __DIR__ . '/../../../etc/di.xml';
        self::assertFileExists($diXmlPath);

        $xml = simplexml_load_file($diXmlPath);
        self::assertInstanceOf(SimpleXMLElement::class, $xml);

        $offenders = [];
        $bannedHits = [];
        $checked = 0;
        foreach ($xml->type as $type) {
            $name = (string)$type['name'];
            if ($name === '') {
                continue;
            }
            $checked++;

            if (in_array($name, self::KNOWN_BAD_CLASSES, true)) {
                $bannedHits[] = $name;
                continue;
            }

            $isRealMagentoClass = class_exists($name) || interface_exists($name);
            $isOnAllowlist = in_array($name, self::KNOWN_MAGENTO_CLASSES, true);
            if (!$isRealMagentoClass && !$isOnAllowlist) {
                $offenders[] = $name;
            }
        }

        self::assertGreaterThan(
            0,
            $checked,
            'di.xml has no <type name="..."> elements — test fixture is wrong.'
        );

        self::assertSame(
            [],
            $bannedHits,
            'di.xml references a class on the known-bad list (Bug C). Suggested fix: '
            . 'Magento\Tax\Model\Sales\Total\Quote\Tax. Offending entries: '
            . implode(', ', $bannedHits)
        );

        self::assertSame(
            [],
            $offenders,
            "di.xml references unstubbed/un-allowlisted Magento classes. Either stub them "
            . "in Test/Stubs/MagentoFramework.php OR add to KNOWN_MAGENTO_CLASSES with a "
            . "verifying vendor/magento/... path comment. Offenders: "
            . implode(', ', $offenders)
        );
    }

    /**
     * Lightweight invariant: the totals plugin MUST be registered against
     * the Tax-module collector (the corrected post-Bug-C target). Catches
     * a future regression that accidentally moves it back to the Quote
     * module's non-existent class.
     */
    public function testTotalsPluginTargetsTaxModuleCollector(): void
    {
        $diXmlPath = __DIR__ . '/../../../etc/di.xml';
        $xml = simplexml_load_file($diXmlPath);
        self::assertInstanceOf(SimpleXMLElement::class, $xml);

        $found = false;
        foreach ($xml->type as $type) {
            foreach ($type->plugin as $plugin) {
                $pluginType = (string)$plugin['type'];
                if ($pluginType === 'EJOsterberg\OpenSalesTax\Plugin\QuoteTotalsTaxPlugin') {
                    $found = true;
                    self::assertSame(
                        'Magento\Tax\Model\Sales\Total\Quote\Tax',
                        (string)$type['name'],
                        'QuoteTotalsTaxPlugin must target Magento\Tax\Model\Sales\Total\Quote\Tax (Bug C). '
                        . 'Got: ' . (string)$type['name']
                    );
                }
            }
        }

        self::assertTrue($found, 'QuoteTotalsTaxPlugin not registered in di.xml at all.');
    }
}
