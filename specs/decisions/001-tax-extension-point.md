# ADR 001 — Tax extension point: plugin on `Calculation::getRate`

**Status:** Accepted
**Date:** 2026-05-13
**Phase:** v0.1.0 alpha (stage 02, task 4)

## Context

The constitution (§2) requires the module to swap Magento's tax rate calculation with rates derived from the OpenSalesTax engine. Two candidate hook points exist:

| Pattern | Target | Surface |
|---|---|---|
| **A** | `<preference>` on `Magento\Tax\Api\TaxCalculationInterface` | Replaces the entire `TaxCalculationInterface` implementation |
| **B** | `<plugin>` on `Magento\Tax\Model\Calculation::getRate` | Intercepts a single rate-lookup method |

Both options require, in addition, a `<plugin>` on `Magento\Quote\Model\Quote\Address\Total\Tax::collect` to surface per-line tax breakdown in checkout and order summaries. That part is not in question.

## Decision

**We choose pattern B — `<plugin>` on `Magento\Tax\Model\Calculation::getRate` (using `afterGetRate`).**

The DI declaration in `EJOsterberg/OpenSalesTax/etc/di.xml`:

```xml
<type name="Magento\Tax\Model\Calculation">
    <plugin name="ejosterberg_opensalestax_calculation"
            type="EJOsterberg\OpenSalesTax\Plugin\CalculationPlugin"
            sortOrder="10"/>
</type>
```

## Rationale

### Conflict surface (the deciding factor)

Magento's DI allows multiple plugins on the same method (executed in `sortOrder`), but a single class can have only one `<preference>` declared per object scope. If another module — including the merchant's own customizations or a tax-adjacent third party (e.g., AvaTax, TaxJar, Avalara) — already preferences `TaxCalculationInterface`, the load order is undefined and the last preference wins. Plugins coexist cleanly.

A merchant who tries this module alongside another tax extension under pattern A will hit a hard conflict at boot. Under pattern B, both modules can plug `getRate` and Magento merges their effects via plugin sort order.

### Narrower seam

`getRate(\Magento\Framework\DataObject $request)` returns a single percent for one rate lookup. That is precisely what we need to override per destination. `TaxCalculationInterface::calculateTax(...)` has a much wider contract (it returns a populated `QuoteDetailsItemInterface` with all the per-line totals), and reimplementing that surface for every quote shape is invitation for subtle regressions.

### Magento docs preference

Adobe's [DI / plugin guidance](https://developer.adobe.com/commerce/php/development/components/plugins/) explicitly favors plugins over preferences when the seam is narrow enough: "Use plugins to extend existing classes; use preferences when you need to replace a class." Replacing `TaxCalculation` wholesale is heavier than we need.

### Easier rollback

A plugin can be soft-disabled by toggling the admin config flag (`osstax/general/api_url` blank → plugin short-circuits to the original result). A preference cannot be similarly contained without restarting the DI compile step.

## Consequences

### What this means for the implementation

- `EJOsterberg/OpenSalesTax/Plugin/CalculationPlugin.php` implements `afterGetRate($subject, float $result, \Magento\Framework\DataObject $request): float`.
- The plugin reads from a request-scoped `QuoteTaxRegistry` populated by `QuoteTotalsTaxPlugin::beforeCollect`. If the registry has no entry for this destination (e.g., the gate decided non-US / non-USD or the engine was unreachable in fail-soft mode), the plugin returns `$result` unchanged — Magento's built-in tax tables drive the calc.
- The plugin does **not** call the OST engine itself. Engine I/O happens once per quote in `QuoteTotalsTaxPlugin::beforeCollect`. The registry decouples the two plugins and keeps `getRate` (which Magento calls many times per quote) cheap.

### What we give up

- We don't own the full `calculateTax` flow. If a future feature needs to override how Magento applies subtotals (e.g., "tax-included pricing with custom rounding"), we will need a second hook — possibly a plugin on `TaxCalculation::calculateTax`, possibly a preference. v0.1 doesn't need that.

### Reversibility

Switching to pattern A in a future release is a non-breaking change for merchants — they still install the same composer package, the same admin config fields apply, and the engine contract doesn't change. Only `etc/di.xml` and one PHP class swap. We will not rule out pattern A forever; we are choosing the smaller seam *first*.

## References

- `specs/research/magento-tax-module.md` §2, §3
- <https://developer.adobe.com/commerce/php/development/components/plugins/>
- <https://developer.adobe.com/commerce/php/development/components/dependency-injection/>
