define([
    'jquery',
    'Magento_Customer/js/customer-data',
    'Magento_Customer/js/model/authentication-popup',
    'Magento_Ui/js/modal/alert',
    'mage/translate',
    'Printess_PrintessEditor/js/printess-integration'
], function ($, customerData, authenticationPopup, alert, $t, PrintessEditor) {
    'use strict';

    var pendingActionKey = 'printess-pending-design';

    function getPendingAction() {
        try {
            return JSON.parse(window.sessionStorage.getItem(pendingActionKey));
        } catch (error) {
            return null;
        }
    }

    function setPendingAction(config) {
        try {
            window.sessionStorage.setItem(pendingActionKey, JSON.stringify({
                productId: String(config.productId),
                path: window.location.pathname + window.location.search
            }));
        } catch (error) {
            // Session storage is optional.
        }
    }

    function clearPendingAction(config) {
        var pendingAction = getPendingAction();

        if (pendingAction && pendingAction.productId === String(config.productId)) {
            try {
                window.sessionStorage.removeItem(pendingActionKey);
            } catch (error) {
                // Ignore storage errors.
            }
        }
    }

    function isCurrentAction(config) {
        var pendingAction = getPendingAction();

        return pendingAction &&
            pendingAction.productId === String(config.productId) &&
            pendingAction.path === window.location.pathname + window.location.search;
    }

    function isLoggedIn(customer) {
        return Boolean(customer() && customer().firstname);
    }

    function saveProject(config, saveToken, thumbnailUrl, projectName) {
        return $.ajax({
            url: config.saveUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                form_key: window.FORM_KEY,
                project_id: config.projectId || '',
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
        require(['Printess_PrintessEditor/js/printess-integration'], function (PrintessEditor) {
            PrintessEditor.openFromProduct({
                shopToken: config.shopToken,
                templateName: config.templateName,
                formId: config.formId,
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
                pageCountOptionId: config.pageCountOptionId || null,
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
                                if (config.projectsUrl) {
                                    window.location.href = config.projectsUrl;
                                }
                            }
                        }, function (err) {
                            PrintessEditor.hideCartLoader();
                            return Promise.reject(err);
                        });
                    });
                }
            });
        }, function () {
            clearPendingAction(config);
            alert({
                title: $t('Unable to Open Project'),
                content: $t('The Printess editor could not be loaded. Please reload the page and try again.')
            });
        });
    }

    function showLoginModal(config) {
        var modalElement;

        if (!authenticationPopup.modalWindow) {
            modalElement = document.querySelector('#authenticationPopup .block-authentication');

            if (modalElement) {
                authenticationPopup.createPopUp(modalElement);
            }
        }

        if (!authenticationPopup.modalWindow) {
            clearPendingAction(config);
            return;
        }

        $(authenticationPopup.modalWindow).one('modalclosed.printessLoginGate', function () {
            clearPendingAction(config);
        });
        authenticationPopup.showModal();
    }

    return function (config, element) {
        var customer = customerData.get('customer'),
            $element = $(element);

        customer.subscribe(function (customerDataValue) {
            if (!isLoggedIn(function () { return customerDataValue; })) {
                return;
            }

            if (isCurrentAction(config)) {
                clearPendingAction(config);
                openEditor(config);
            }
        });

        $element.on('click', function (event) {
            event.preventDefault();
            event.stopPropagation();

            if (isLoggedIn(customer)) {
                openEditor(config);
                return;
            }

            setPendingAction(config);
            showLoginModal(config);
        });
    };
});
