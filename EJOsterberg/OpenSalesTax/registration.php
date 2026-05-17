<?php
// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later
/**
 * Magento module registration for EJOsterberg_OpenSalesTax.
 *
 * Invoked by Magento's autoloader at framework bootstrap time. Registers
 * this directory as a Magento component so `bin/magento module:enable
 * EJOsterberg_OpenSalesTax` resolves.
 *
 * The class_exists guard means this file is also safe to load in
 * non-Magento contexts (e.g., our own test suite where the framework
 * stubs are loaded separately).
 */
declare(strict_types=1);

if (class_exists(\Magento\Framework\Component\ComponentRegistrar::class)) {
    \Magento\Framework\Component\ComponentRegistrar::register(
        \Magento\Framework\Component\ComponentRegistrar::MODULE,
        'EJOsterberg_OpenSalesTax',
        __DIR__
    );
}
