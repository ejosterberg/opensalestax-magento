<?php
// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Controller\Adminhtml\Connection;

use EJOsterberg\OpenSalesTax\Model\ConnectionTester;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * Admin AJAX endpoint for the "Test Connection" button.
 *
 * Route: opensalestax/connection/test (admin frontName: opensalestax).
 * Hits the configured engine's /v1/health via ConnectionTester and returns
 * a small JSON envelope the system.xml-injected JS renders inline.
 *
 * ACL: tied to the existing module config resource so anyone allowed to
 * edit the OpenSalesTax settings can also probe the engine — matches
 * the principle of least surprise for merchants.
 */
class Test extends Action
{
    public const ADMIN_RESOURCE = 'EJOsterberg_OpenSalesTax::config';

    public function __construct(
        Context $context,
        private readonly ConnectionTester $tester
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $envelope = $this->tester->test();
        /** @var \Magento\Framework\Controller\Result\Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $result->setData($envelope);
        return $result;
    }
}
