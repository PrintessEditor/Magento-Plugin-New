define([
    'Magento_Ui/js/form/element/abstract',
    'ko'
], function (Abstract, ko) {
    'use strict';

    function proxyPost(url, payload) {
        var formKey = (typeof window !== 'undefined' && window.FORM_KEY) ? window.FORM_KEY : '';
        var sep = url.indexOf('?') >= 0 ? '&' : '?';
        var fullUrl = url + sep + 'isAjax=1' + (formKey ? '&form_key=' + encodeURIComponent(formKey) : '');
        return fetch(fullUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            credentials: 'same-origin',
            body: JSON.stringify(payload || {})
        }).then(function (r) {
            var status = r.status;
            return r.text().then(function (text) {
                var parsed;
                try { parsed = JSON.parse(text); } catch (e) {
                    return {ok: false, data: null, error: 'Non-JSON response (HTTP ' + status + ')'};
                }
                if (parsed && parsed.error === true && typeof parsed.message === 'string') {
                    return {ok: false, data: null, error: parsed.message};
                }
                if (!r.ok) {
                    return {ok: false, data: parsed, error: (parsed && parsed.error) || 'HTTP ' + status};
                }
                return {ok: true, data: parsed, error: null};
            });
        }, function (err) {
            return {ok: false, data: null, error: 'Network error: ' + err.message};
        });
    }

    return Abstract.extend({
        defaults: {
            template: 'ui/form/field',
            elementTmpl: 'Printess_PrintessEditor/product/form/element/form-fields-editor',
            endpointFormFields: '',
            currentTemplateName: '',
            imports: {
                currentTemplateName: '${ $.provider }:data.product.printess_template'
            },
            listens: {
                currentTemplateName: 'onTemplateChange'
            }
        },

        // initObservable runs before initLinks, so all observables exist when
        // imports fires and listens calls onTemplateChange for the first time.
        initObservable: function () {
            this._super();
            this._lastLoadedTemplate = '';
            this.availableFields = ko.observableArray([]);
            this.loadingFields = ko.observable(false);
            this.fieldsError = ko.observable('');
            this.rows = ko.observableArray([]);
            this.availableFieldOptions = ko.computed(function () {
                return [{value: '', label: '-- Select field --'}].concat(
                    this.availableFields().map(function (f) {
                        return {value: f.name, label: f.display || f.name};
                    })
                );
            }, this);
            return this;
        },

        initialize: function () {
            this._super();

            // Populate rows from stored value (after _super so initValue has run)
            var initial = this.value();
            if (Array.isArray(initial) && initial.length) {
                this._initRows(initial);
            }

            // Keep value in sync with rows
            var self = this;
            ko.computed(function () {
                var data = self.rows().map(function (r) {
                    return {fieldName: r.fieldName(), fieldValue: r.fieldValue()};
                }).filter(function (r) { return r.fieldName !== ''; });
                self.value(data);
            }).extend({rateLimit: 100});

            return this;
        },

        onTemplateChange: function (templateName) {
            if (templateName === this._lastLoadedTemplate) { return; }
            this._lastLoadedTemplate = templateName;
            this.availableFields([]);
            this.fieldsError('');
            if (templateName) {
                this._loadFormFields(templateName);
            }
        },

        _loadFormFields: function (templateName) {
            if (!this.endpointFormFields) { return; }
            var self = this;
            self.loadingFields(true);
            proxyPost(self.endpointFormFields, {templateName: templateName}).then(function (res) {
                self.loadingFields(false);
                if (res.ok && res.data && Array.isArray(res.data.formFields)) {
                    self.availableFields(res.data.formFields);
                } else {
                    self.fieldsError(
                        (typeof res.error === 'string' ? res.error : null) || 'Could not load form fields.'
                    );
                }
            });
        },

        _initRows: function (data) {
            var self = this;
            this.rows(data.map(function (r) {
                return self._makeRow(r.fieldName || '', r.fieldValue || '');
            }));
        },

        _makeRow: function (name, val) {
            var self = this;
            var fieldName = ko.observable(name);
            var fieldValue = ko.observable(val);
            var entries = ko.computed(function () {
                var n = fieldName();
                if (!n) { return []; }
                var fields = self.availableFields();
                for (var i = 0; i < fields.length; i++) {
                    if (fields[i].name === n) {
                        var raw = fields[i].entries;
                        if (Array.isArray(raw) && raw.length) {
                            return raw.map(function (e) {
                                return {key: e.key, displayLabel: e.label || e.key};
                            });
                        }
                        return [];
                    }
                }
                return [];
            });

            entries.subscribe(function (newEntries) {
                if (newEntries.length) {
                    var current = fieldValue();
                    var valid = newEntries.some(function (e) { return e.key === current; });
                    if (!valid) { fieldValue(''); }
                }
            });

            return {fieldName: fieldName, fieldValue: fieldValue, entries: entries};
        },

        addRow: function () {
            this.rows.push(this._makeRow('', ''));
        },

        removeRow: function (index) {
            this.rows.splice(index, 1);
        }
    });
});
