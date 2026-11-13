define([
    'Printess_PrintessEditor/js/printess-integration'
], function (PrintessEditor) {
    'use strict';

    function openEditor(config) {
        PrintessEditor.openFromProduct({
            shopToken: config.shopToken,
            panelLoaderUrl: config.panelLoaderUrl,
            templateName: config.templateName,
            restoreSavedDesign: true,
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
            projectId: config.projectId || null,
            // Supervisor approval: submit instead of add to cart / view-only for locked designs
            approvalSubmit: config.approvalSubmit === undefined ? undefined : config.approvalSubmit,
            approval: config.approval || null,
            presetSelections: config.presetSelections || null
        });
    }

    return function (config) {
        openEditor(config);
    };
});
