<?php
// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Block\Adminhtml\Form\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Custom system-config field renderer for the "Test Connection" button.
 *
 * Wired in system.xml via `frontend_model`. Renders a `<button>` plus an
 * empty `<span>` result holder; the JS that ships in
 * view/adminhtml/web/js/test-connection.js wires the click handler.
 */
class TestButton extends Field
{
    public function __construct(Context $context, array $data = [])
    {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $url = $this->getUrl('opensalestax/connection/test');
        return sprintf(
            '<button type="button" class="action-default scalable" '
            . 'id="opensalestax-test-connection" data-test-url="%s">%s</button> '
            . '<span id="opensalestax-test-result" style="margin-left:1em;font-family:monospace;"></span>',
            $this->escapeUrl($url),
            $this->escapeHtml(__('Test Connection'))
        );
    }
}
