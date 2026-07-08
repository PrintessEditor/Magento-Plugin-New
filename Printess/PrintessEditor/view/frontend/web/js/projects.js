define([
    'jquery',
    'Magento_Ui/js/modal/alert',
    'Magento_Ui/js/modal/confirm',
    'mage/translate'
], function ($, alert, confirm, $t) {
    'use strict';

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
                    stopLoader();
                    alert({
                        title: $t('Unable to Open Project'),
                        content: (response && response.message) || $t('The project could not be opened. Please try again.')
                    });
                    return;
                }

                require(['Printess_PrintessEditor/js/project-edit'], function (openProject) {
                    try {
                        openProject(response.config);
                    } finally {
                        stopLoader();
                    }
                }, function () {
                    stopLoader();
                    alert({
                        title: $t('Unable to Open Project'),
                        content: $t('The Printess editor could not be loaded. Please reload the page and try again.')
                    });
                });
            }, function (xhr) {
                stopLoader();
                var response = xhr.responseJSON || {};

                alert({
                    title: $t('Unable to Open Project'),
                    content: response.message || $t('The project could not be opened. Please try again.')
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
