define([
    'jquery',
    'Magento_Ui/js/modal/alert',
    'Magento_Ui/js/modal/confirm',
    'mage/translate'
], function ($, alert, confirm, $t) {
    'use strict';

    // Cache the openProject function after first load so subsequent clicks
    // bypass require() — Magento's require.js enters a bad state after the
    // Printess component is loaded and removed from the DOM on first close.
    var _cachedOpenProject = null;

    function startLoader() {
        $('body').trigger('processStart');
    }

    function stopLoader() {
        $('body').trigger('processStop');
    }

    return function (config, element) {
        $(element).on('click', '.printess-project-continue', function (event) {
            var projectId = $(this).data('project-id');

            event.preventDefault();
            event.stopPropagation();
            startLoader();
            // Safety net: if the editor flow fails to call stopLoader within 10s, stop it anyway.
            var _loadSafetyTimer = setTimeout(function () {
                console.warn('[Printess] Safety stopLoader triggered after 10s — something blocked the require() callback');
                stopLoader();
            }, 10000);
            function _stopLoader() {
                clearTimeout(_loadSafetyTimer);
                stopLoader();
            }

            $.ajax({
                url: config.openUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    form_key: window.FORM_KEY,
                    project_id: projectId
                }
            }).then(function (response) {
                if (!response || response.success !== true || !response.config) {
                    _stopLoader();
                    alert({
                        title: $t('Unable to Open Project'),
                        content: (response && response.message) || $t('The project could not be opened. Please try again.')
                    });
                    return;
                }

                if (_cachedOpenProject) {
                    // Use the cached module reference to avoid calling require() again.
                    // Magento's require.js enters a bad state after the Printess component
                    // is loaded and its DOM element is removed, causing subsequent require()
                    // calls to silently drop their callbacks.
                    try {
                        _cachedOpenProject(response.config);
                    } finally {
                        _stopLoader();
                    }
                    return;
                }
                require(['Printess_PrintessEditor/js/project-edit'], function (openProject) {
                    _cachedOpenProject = openProject;
                    try {
                        openProject(response.config);
                    } finally {
                        _stopLoader();
                    }
                }, function () {
                    console.error('[Printess] Failed to require project-edit module');
                    _stopLoader();
                    alert({
                        title: $t('Unable to Open Project'),
                        content: $t('The Printess editor could not be loaded. Please reload the page and try again.')
                    });
                });
            }, function (xhr) {
                console.error('[Printess] AJAX request failed', xhr.status, xhr.responseText);
                _stopLoader();

                alert({
                    title: $t('Unable to Open Project'),
                    content: (xhr.responseJSON && xhr.responseJSON.message) || $t('The project could not be opened. Please try again.')
                });
            });
        });

        $(element).on('click', '.printess-project-rename-toggle', function (event) {
            event.preventDefault();
            var $project = $(this).closest('.printess-project-details');
            $project.find('.printess-project-title').hide();
            $project.find('.printess-project-rename').show().find('.input-text').trigger('focus').trigger('select');
        });

        $(element).on('click', '.printess-project-rename-cancel', function (event) {
            event.preventDefault();
            var $project = $(this).closest('.printess-project-details');
            $project.find('.printess-project-rename').hide();
            $project.find('.printess-project-title').show();
        });

        $(element).on('submit', '.printess-project-rename', function () {
            startLoader();
        });

        $(element).on('submit', '.printess-project-delete', function (event) {
            var form = this;

            if ($(form).data('confirmed')) {
                return;
            }

            event.preventDefault();
            confirm({
                title: $t('Delete Project'),
                content: $(form).data('confirm-message'),
                actions: {
                    confirm: function () {
                        $(form).data('confirmed', true).trigger('submit');
                    }
                }
            });
        });
    };
});
