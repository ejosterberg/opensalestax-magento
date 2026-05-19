# EJOsterberg_OstaxTestStubs — Mg-1.1 test-only Magento module

This directory is a Magento module that exists **only** to support the
`LiveMagentoTaxTest` CI integration test. It is NOT shipped to
merchants who install the OpenSalesTax module via composer (it lives
under `tests/Integration/` which is excluded from the composer
package, and the module-name vendor is `EJOsterberg_OstaxTestStubs`
specifically to make its scope obvious).

## What it does

Maps `Magento\InventoryDistanceBasedSourceSelectionApi\Api\GetLatsLngsFromAddressInterface`
to a no-op stub class. This works around an upstream Magento OSS DI
bug (present in at least 2.4.6-p10 and 2.4.7-p3) that crashes
`$quote->collectTotals()` whenever the multi-source-inventory
geocoding branch runs.

See `Stubs/GetLatsLngsFromAddressInterfaceStub.php` and `etc/di.xml`
for the full rationale.

## How it is deployed

The `integration-magento.yml` workflow copies this entire directory
into `$MAGENTO_DIR/app/code/EJOsterberg/OstaxTestStubs/` after
`composer create-project` and before `composer install`. Magento's
PSR-0 autoloader maps `app/code/` for any vendor, and
`app/etc/NonComposerComponentRegistration.php` walks `registration.php`
files, so no further wiring is needed.

## When to delete this

When either:

1. Adobe ships a Magento OSS patch release that fixes the underlying
   DI resolution bug (then we can revert to using the upstream
   concrete `GetLatsLngsFromAddress`).
2. We migrate the integration test off `dev/tests/integration` to a
   leaner harness that doesn't trigger the broken branch (Mg-1.2 in
   the portfolio improvement queue).

Track at `portfolio/improvement-queue.md` → Mg-1.1.
