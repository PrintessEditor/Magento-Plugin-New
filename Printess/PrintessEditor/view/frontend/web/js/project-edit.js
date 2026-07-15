define([
    'jquery',
    'Magento_Ui/js/modal/alert',
    'mage/translate',
    'Printess_PrintessEditor/js/printess-integration'
], function ($, alert, $t, PrintessEditor) {
    'use strict';

    function saveProject(config, saveToken, thumbnailUrl, projectName) {
        return $.ajax({
            url: config.saveUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                form_key: window.FORM_KEY,
                project_id: config.projectId,
                product_id: config.productId,
                save_token: saveToken,
                thumbnail_url: thumbnailUrl || '',
                project_name: projectName || ''
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
        PrintessEditor.openFromProduct({
            shopToken: config.shopToken,
            templateName: config.templateName,
            formId: 'product_addtocart_form',
            addToCartUrl: config.addToCartUrl || '',
            productId: config.productId || '',
            formKey: config.formKey || '',
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
            onAddToBasket: function (saveToken, thumbnailUrl) {
                var validThumbnail = (typeof thumbnailUrl === 'string' && thumbnailUrl.indexOf('https://') === 0)
                    ? thumbnailUrl
                    : '';
                return PrintessEditor.promptProjectName(config._namePrompted ? '\x01' : (config.projectName || '')).then(function (name) {
                    config._namePrompted = true;
                    if (name) { config.projectName = name; }
                    PrintessEditor.showCartLoader('Adding to Cart...');
                    return saveProject(config, saveToken, validThumbnail, config.projectName || name).then(function (result) {
                        return result;
                    }, function (err) {
                        PrintessEditor.hideCartLoader();
                        return Promise.reject(err);
                    });
                });
            },
            saveTemplateCallback: function (saveToken, type, thumbnailUrl) {
                var validThumbnail = (typeof thumbnailUrl === 'string' && thumbnailUrl.indexOf('https://') === 0)
                    ? thumbnailUrl
                    : '';
                return PrintessEditor.promptProjectName(config._namePrompted ? '\x01' : (config.projectName || '')).then(function (name) {
                    config._namePrompted = true;
                    if (name) { config.projectName = name; }
                    if (type === 'close') { PrintessEditor.showCartLoader('Saving...'); }
                    return saveProject(config, saveToken, validThumbnail, config.projectName || name).then(function () {
                        if (type === 'close') {
                            window.location.href = config.returnUrl;
                        }
                    }, function (err) {
                        PrintessEditor.hideCartLoader();
                        return Promise.reject(err);
                    });
                });
            }
        });
    }

    return function (config) {
        openEditor(config);
    };
});
