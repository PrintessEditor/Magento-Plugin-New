define([
    'jquery',
    'Magento_Ui/js/modal/alert',
    'mage/translate'
], function ($, alert, $t) {
    'use strict';

    function saveProject(config, saveToken, thumbnailUrl) {
        return $.ajax({
            url: config.saveUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                form_key: window.FORM_KEY,
                project_id: config.projectId,
                product_id: config.productId,
                save_token: saveToken,
                thumbnail_url: thumbnailUrl || ''
            }
        }).then(function (response) {
            if (!response || response.success !== true) {
                return $.Deferred().reject(response).promise();
            }

            config.projectId = response.project_id;
            return response;
        }, function (xhr) {
            var response = xhr.responseJSON || {};

            alert({
                title: $t('Unable to Save Project'),
                content: response.message || $t('The project could not be saved. Please try again.')
            });

            return $.Deferred().reject(xhr).promise();
        });
    }

    function openEditor(config) {
        require(['Printess_PrintessEditor/js/printess-integration'], function (PrintessEditor) {
            PrintessEditor.openFromProduct({
                shopToken: config.shopToken,
                templateName: config.templateName,
                formId: 'product_addtocart_form',
                variantOptions: config.variantOptions || [],
                customOptions: config.customOptions || [],
                pagePricing: config.pagePricing || [],
                basePrice: config.basePrice || 0,
                currencyCode: config.currencyCode,
                locale: config.locale,
                theme: config.theme,
                magicPhotobookTheme: config.magicPhotobookTheme,
                printSettings: config.printSettings,
                mergeTemplate: config.mergeTemplate,
                onAddToBasket: function () {
                    alert({
                        title: $t('Unable to Add to Basket'),
                        content: $t('Use the product page to add this design to the basket.')
                    });
                    return $.Deferred().reject(new Error('add to basket unavailable')).promise();
                },
                saveTemplateCallback: function (saveToken, type, thumbnailUrl) {
                    var validThumbnail = (typeof thumbnailUrl === 'string' && thumbnailUrl.indexOf('https://') === 0)
                        ? thumbnailUrl
                        : '';
                    return saveProject(config, saveToken, validThumbnail).then(function () {
                        if (type === 'close') {
                            window.location.href = config.returnUrl;
                        }
                    });
                }
            });
        }, function () {
            alert({
                title: $t('Unable to Open Project'),
                content: $t('The Printess editor could not be loaded. Please reload the page and try again.')
            });
        });
    }

    return function (config) {
        openEditor(config);
    };
});
