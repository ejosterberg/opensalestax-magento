// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later
//
// Wires the "Test Connection" button rendered by Block\Adminhtml\Form\Field\TestButton
// to the adminhtml controller at opensalestax/connection/test. Renders the JSON
// envelope inline below the button. No navigation, no Magento messages bar.
//
// Magento ships the admin form_key in `window.FORM_KEY`; we include it as
// the conventional `form_key` POST param even though our controller is a
// read-only probe (no state change). This avoids surprising the merchant
// if Magento's CSRF middleware tightens later.

require(['jquery'], function ($) {
    'use strict';

    $(function () {
        var $btn = $('#opensalestax-test-connection');
        if ($btn.length === 0) {
            return;
        }
        var $result = $('#opensalestax-test-result');

        $btn.on('click', function (e) {
            e.preventDefault();
            var url = $btn.data('test-url');
            $result.css('color', '').text('Testing…');

            $.ajax({
                url: url,
                type: 'POST',
                dataType: 'json',
                data: { form_key: window.FORM_KEY || '' }
            }).done(function (data) {
                if (data && data.ok) {
                    $result.css('color', 'green').text('✓ ' + (data.message || 'OK'));
                } else {
                    $result.css('color', '#d63638').text(
                        '✗ ' + (data && data.error ? data.error : 'Unknown error')
                    );
                }
            }).fail(function (jqXhr) {
                $result.css('color', '#d63638').text(
                    '✗ HTTP ' + jqXhr.status + ' — ' + (jqXhr.statusText || 'request failed')
                );
            });
        });
    });
});
