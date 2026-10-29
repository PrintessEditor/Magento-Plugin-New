/**
 * "Edit & Reorder" button on the customer order view page.
 *
 * Flow: check whether the order item's Printess save token is still active (tokens
 * expire) -> if so, reopen it in the Panel editor via the same openFromProduct() path
 * used by the product page and "Continue Editing" (My Projects), so the customer can
 * review/adjust before it's added to the cart as a brand-new item -> if the token has
 * expired, tell the customer and offer a new design on the product template, preset with the
 * old order's settings (form fields, custom options, variant; f22).
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

            function showMessage(text, productUrl, newDesignConfig) {
                $message.empty();
                $message.append($('<span>').text(text));
                if (newDesignConfig) {
                    // f22: a new design on the product template, preset with the old order's settings.
                    $message.append(document.createTextNode(' '));
                    $message.append($('<button type="button" class="action secondary printess-new-design-button">')
                        .text($t('Start a new design with the same settings'))
                        .on('click', function (e) {
                            e.preventDefault();
                            startLoader();
                            withEditor(newDesignConfig);
                        }));
                } else if (productUrl) {
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
                        response.productUrl,
                        response.newDesign || null
                    );
                    return;
                }

                withEditor(response.config);
            }, function (xhr) {
                done();
                showMessage((xhr.responseJSON && xhr.responseJSON.message) || $t('Could not check this design. Please try again.'));
            });

            function withEditor(editorConfig) {
                function openIt(openFromProduct) {
                    try {
                        openFromProduct({
                            shopToken: editorConfig.shopToken,
                            panelLoaderUrl: editorConfig.panelLoaderUrl,
                            templateName: editorConfig.templateName,
                            // PR #8: reopening a saved design restores its own form fields and skips merge
                            // templates. A new design (f22) must not, or its preset would be dropped.
                            restoreSavedDesign: editorConfig.restoreSavedDesign !== false,
                            formId: 'product_addtocart_form',
                            addToCartUrl: editorConfig.addToCartUrl,
                            productId: editorConfig.productId,
                            formKey: editorConfig.formKey,
                            variantOptions: editorConfig.variantOptions || [],
                            customOptions: editorConfig.customOptions || [],
                            pagePricing: editorConfig.pagePricing || [],
                            basePrice: editorConfig.basePrice || 0,
                            currencyCode: editorConfig.currencyCode,
                            locale: editorConfig.locale,
                            theme: editorConfig.theme,
                            magicPhotobookTheme: editorConfig.magicPhotobookTheme,
                            printSettings: editorConfig.printSettings,
                            mergeTemplates: editorConfig.mergeTemplates,
                            predefinedFormFields: editorConfig.predefinedFormFields || [],
                            presetSelections: editorConfig.presetSelections || null,
                            shopUserId: editorConfig.shopUserId || '',
                            productName: editorConfig.productName || '',
                            productUrl: editorConfig.productUrl || '',
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
            }
        });
    };
});
