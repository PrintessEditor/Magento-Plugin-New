define([
    'Printess_PrintessEditor/js/printess-integration'
], function (PrintessEditor) {
    'use strict';

    function openEditor(config) {
        PrintessEditor.openFromProduct({
            shopToken: config.shopToken,
            panelLoaderUrl: config.panelLoaderUrl,
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
            mergeTemplates: config.mergeTemplates,
            shopUserId: config.shopUserId || '',
            productName: config.productName || '',
            productUrl: config.productUrl || '',
            isLoggedIn: true,
            projectId: config.projectId || null
        });
    }

    return function (config) {
        openEditor(config);
    };
});
