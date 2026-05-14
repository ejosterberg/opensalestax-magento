# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-05-13

### Added
- Initial alpha release of the OpenSalesTax module for Magento 2 (`^2.4.6`).
- `Plugin\CalculationPlugin` — plugin on `Magento\Tax\Model\Calculation::getRate` that returns OST-derived effective rates per quote.
- `Plugin\QuoteTotalsTaxPlugin` — plugin on `Magento\Quote\Model\Quote\Address\Total\Tax::collect` that pre-warms a request-scoped registry with the engine's response and writes per-jurisdiction breakdown onto the totals.
- `Model\OstaxClient` — HTTP client for the OST engine v1 API (`/v1/calculate`, `/v1/health`) wrapping `Magento\Framework\HTTP\Client\Curl`.
- `Model\Config` — admin settings reader with token decryption via `Magento\Framework\Encryption\EncryptorInterface`.
- `Model\QuoteTaxRegistry` — request-scoped registry shared between the two plugins.
- Admin config section at Stores → Configuration → Sales → Tax → **OpenSalesTax** (API URL, optional encrypted token, fail-hard toggle), gated by ACL resource `EJOsterberg_OpenSalesTax::config`.
- USD/US-only gating: non-US destinations and non-USD currencies fall through to Magento's built-in `tax_rate` calc.
- Fail-soft default: engine errors fall back to Magento's tax tables and log a warning. Fail-hard opt-in via `osstax/general/fail_hard=1`.
- ADR-001 (`specs/decisions/001-tax-extension-point.md`) — chose plugin on `Calculation::getRate` over preference on `TaxCalculationInterface`.
- CI on PHP 8.1 / 8.2 / 8.3 via GitHub Actions.
- 33 unit tests covering Config, OstaxClient, OstaxResponse, QuoteTaxRegistry, and both plugins.

### Security
- API tokens stored encrypted in `core_config_data` via `Magento\Config\Model\Config\Backend\Encrypted`.
- Decrypted in memory only at request time, never logged.
- Customer addresses and full payloads excluded from log statements.

[Unreleased]: https://github.com/ejosterberg/opensalestax-magento/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/ejosterberg/opensalestax-magento/releases/tag/v0.1.0
