/**
 * Approval detail page (SUP-08/11/12/13): opens the design in the editor (supervisor view/edit, submitter
 * edit/view), confirms retraction and adds approved designs to the cart.
 */
define([
    'jquery',
    'Magento_Ui/js/modal/alert',
    'Magento_Ui/js/modal/confirm',
    'mage/translate'
], function ($, alert, confirm, $t) {
    'use strict';

    var _openProject = null;

    function startLoader() { $('body').trigger('processStart'); }
    function stopLoader() { $('body').trigger('processStop'); }

    function fail(title, response) {
        stopLoader();
        alert({
            title: title,
            content: (response && response.message) || $t('Please reload the page and try again.')
        });
    }

    function openEditor(editorConfig) {
        function run(openProject) {
            _openProject = openProject;
            try {
                openProject(editorConfig);
            } finally {
                stopLoader();
            }
        }
        if (_openProject) {
            run(_openProject);
            return;
        }
        require(['Printess_PrintessEditor/js/project-edit'], run, function () {
            fail($t('Unable to open the editor'));
        });
    }

    return function (config, element) {
        var $root = $(element);

        $root.on('click', '[data-approval-open]', function (event) {
            event.preventDefault();
            var mode = $(this).data('approval-open');
            startLoader();

            if (mode === 'view' || mode === 'edit') {
                // supervisor
                $.ajax({
                    url: config.startEditUrl,
                    type: 'POST',
                    dataType: 'json',
                    data: { form_key: config.formKey, submission_id: config.submissionId, mode: mode }
                }).then(function (response) {
                    if (!response || response.success !== true) {
                        fail($t('Unable to open the design'), response);
                        if (mode === 'edit') { window.location.reload(); }
                        return;
                    }
                    response.config.approvalSubmit = null;
                    openEditor(response.config);
                }, function (xhr) { fail($t('Unable to open the design'), xhr.responseJSON); });
                return;
            }

            // submitter: own project (edit when editable, view-only otherwise — decided by the server)
            $.ajax({
                url: config.openUrl,
                type: 'POST',
                dataType: 'json',
                data: { form_key: config.formKey, project_id: config.projectId }
            }).then(function (response) {
                if (!response || response.success !== true || !response.config) {
                    fail($t('Unable to open the design'), response);
                    return;
                }
                openEditor(response.config);
            }, function (xhr) { fail($t('Unable to open the design'), xhr.responseJSON); });
        });

        $root.on('click', '[data-approval-add-to-cart]', function (event) {
            event.preventDefault();
            startLoader();
            $.ajax({
                url: config.addToCartUrl,
                type: 'POST',
                dataType: 'json',
                data: { form_key: config.formKey, project_id: config.projectId }
            }).then(function (response) {
                if (!response || response.success !== true) {
                    fail($t('The design could not be added to the cart'), response);
                    return;
                }
                window.location.href = response.redirect;
            }, function (xhr) { fail($t('The design could not be added to the cart'), xhr.responseJSON); });
        });

        $root.on('submit', 'form[data-confirm]', function (event) {
            var form = this;
            if ($(form).data('confirmed')) {
                return;
            }
            event.preventDefault();
            confirm({
                content: $(form).data('confirm'),
                actions: {
                    confirm: function () {
                        $(form).data('confirmed', true).trigger('submit');
                    }
                }
            });
        });

        $root.on('submit', 'form.printess-approval-form', function () {
            var area = $(this).find('textarea[required]');
            if (area.length && $.trim(area.val()) === '') {
                area.trigger('focus');
                return false;
            }
            startLoader();
            return true;
        });
    };
});
