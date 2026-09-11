/**
 * "Edit & Reorder" button on the customer order view page.
 *
 * Flow: check whether the order item's Printess save token is still active (tokens
 * expire) -> if so, reopen it in the Panel editor via the same openFromProduct() path
 * used by the product page and "Continue Editing" (My Projects), so the customer can
 * review/adjust before it's added to the cart as a brand-new item -> if the token has
 * expired, tell the customer and point them at the product page to start a fresh design.
 */
define([
    'jquery',
    'Magento_Ui/js/modal/alert',
    'mage/translate'
], function ($, alert, $t) {
    'use strict';

    var _cachedOpenFromProduct = null;

    function startLoader() {
        $('body').trigger('processStart');
    }

    function stopLoader() {
        $('body').trigger('processStop');
    }

    return function (config, element) {
        $(element).on('click', '.printess-reorder-button', function (event) {
            event.preventDefault();

            var $root = $(element);
            var $message = $root.find('.printess-reorder-message');
            $message.hide().empty();

            startLoader();
            var safetyTimer = setTimeout(function () {
                console.warn('[Printess] Safety stopLoader triggered after 10s on reorder check');
                stopLoader();
            }, 10000);

            function done() {
                clearTimeout(safetyTimer);
                stopLoader();
            }

            function showMessage(text, productUrl) {
                $message.empty();
                $message.append($('<span>').text(text));
                if (productUrl) {
                    $message.append(document.createTextNode(' '));
                    $message.append($('<a>').attr('href', productUrl).text($t('Start a new design')));
                }
                $message.show();
            }

            $.ajax({
                url: config.checkUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    form_key: config.formKey || window.FORM_KEY,
                    order_item_id: config.orderItemId
                }
            }).then(function (response) {
                if (!response || response.success !== true) {
                    done();
                    showMessage((response && response.message) || $t('Could not check this design. Please try again.'));
                    return;
                }

                if (!response.active) {
                    done();
                    showMessage(
                        response.message || $t('This design is no longer available and needs to be recreated.'),
                        response.productUrl
                    );
                    return;
                }

                function openIt(openFromProduct) {
                    try {
                        openFromProduct({
                            shopToken: response.config.shopToken,
                            panelLoaderUrl: response.config.panelLoaderUrl,
                            templateName: response.config.templateName,
                            formId: 'product_addtocart_form',
                            addToCartUrl: response.config.addToCartUrl,
                            productId: response.config.productId,
                            formKey: response.config.formKey,
                            variantOptions: response.config.variantOptions || [],
                            customOptions: response.config.customOptions || [],
                            pagePricing: response.config.pagePricing || [],
                            basePrice: response.config.basePrice || 0,
                            currencyCode: response.config.currencyCode,
                            locale: response.config.locale,
                            theme: response.config.theme,
                            magicPhotobookTheme: response.config.magicPhotobookTheme,
                            printSettings: response.config.printSettings,
                            mergeTemplates: response.config.mergeTemplates,
                            shopUserId: response.config.shopUserId || '',
                            productName: response.config.productName || '',
                            productUrl: response.config.productUrl || '',
                            isLoggedIn: true
                        });
                    } finally {
                        done();
                    }
                }

                if (_cachedOpenFromProduct) {
                    // Reuse the cached reference — see printess-login-gate.js/projects.js for
                    // why: require.js can enter a bad state after the Printess component is
                    // loaded and its DOM element removed, silently dropping later callbacks.
                    openIt(_cachedOpenFromProduct);
                    return;
                }

                require(['Printess_PrintessEditor/js/printess-integration'], function (PrintessEditor) {
                    _cachedOpenFromProduct = PrintessEditor.openFromProduct;
                    openIt(PrintessEditor.openFromProduct);
                }, function () {
                    done();
                    alert({
                        title: $t('Unable to Open Editor'),
                        content: $t('The Printess editor could not be loaded. Please reload the page and try again.')
                    });
                });
            }, function (xhr) {
                done();
                showMessage((xhr.responseJSON && xhr.responseJSON.message) || $t('Could not check this design. Please try again.'));
            });
        });
    };
});
