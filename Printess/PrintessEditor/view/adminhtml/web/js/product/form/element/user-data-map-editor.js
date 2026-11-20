/**
 * UDP-02: "Prefill User Data" mapping. Lists every form field of the product's template (incl. the base template,
 * merged by printess/api/formfields) with a dropdown of account data. Value: [{fieldName, source}] for the rows
 * that have a source. Hidden while the "Prefill User Data" switch is off.
 */
define([
    'Magento_Ui/js/form/element/abstract',
    'ko'
], function (Abstract, ko) {
    'use strict';

    function proxyPost(url, payload) {
        var formKey = window.FORM_KEY || '';
        var sep = url.indexOf('?') >= 0 ? '&' : '?';
        return fetch(url + sep + 'isAjax=1' + (formKey ? '&form_key=' + encodeURIComponent(formKey) : ''), {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            credentials: 'same-origin',
            body: JSON.stringify(payload || {})
        }).then(function (r) {
            return r.text().then(function (text) {
                var parsed;
                try { parsed = JSON.parse(text); } catch (e) {
                    return {ok: false, error: 'Non-JSON response (HTTP ' + r.status + ')'};
                }
                if (parsed && parsed.error === true && typeof parsed.message === 'string') {
                    return {ok: false, error: parsed.message};
                }
                if (!r.ok) {
                    return {ok: false, error: (parsed && typeof parsed.error === 'string' && parsed.error) || 'HTTP ' + r.status};
                }
                return {ok: true, data: parsed};
            });
        }, function (err) {
            return {ok: false, error: 'Network error: ' + err.message};
        });
    }

    function isOn(v) {
        return v === true || v === 1 || v === '1' || v === 'true';
    }

    return Abstract.extend({
        defaults: {
            template: 'ui/form/field',
            elementTmpl: 'Printess_PrintessEditor/product/form/element/user-data-map-editor',
            endpointFormFields: '',
            sources: [],
            currentTemplateName: '',
            prefillEnabled: '0',
            imports: {
                currentTemplateName: '${ $.provider }:data.product.printess_template',
                prefillEnabled: '${ $.provider }:data.product.printess_prefill_user_data'
            },
            listens: {
                currentTemplateName: 'onTemplateChange',
                prefillEnabled: 'onPrefillChange'
            }
        },

        initObservable: function () {
            this._super();
            this._lastLoadedTemplate = null;
            this.rows = ko.observableArray([]);
            this.loadingFields = ko.observable(false);
            this.fieldsLoaded = ko.observable(false);
            this.fieldsError = ko.observable('');
            this.baseTemplate = ko.observable('');
            this.hasTemplate = ko.observable(false);
            this.manualName = ko.observable('');
            this.sourceOptions = [{value: '', label: '— none —'}].concat(this.sources || []);
            this.mappedCount = ko.computed(function () {
                return this.rows().filter(function (r) { return r.source() !== ''; }).length;
            }, this);
            return this;
        },

        initialize: function () {
            this._super();
            this.visible(isOn(this.prefillEnabled));
            this._buildRows([]);

            var self = this;
            ko.computed(function () {
                var data = self.rows().filter(function (r) {
                    return r.source() !== '';
                }).map(function (r) {
                    return {fieldName: r.fieldName, source: r.source()};
                });
                self.value(data);
            }).extend({rateLimit: 100});
            return this;
        },

        onPrefillChange: function (v) {
            if (typeof this.visible === 'function') {
                this.visible(isOn(v));
            }
        },

        /** Current mapping (also before the rows exist). */
        _currentMap: function () {
            var map = {};
            var rows = this.rows ? this.rows() : [];
            if (rows.length) {
                rows.forEach(function (r) { if (r.source()) { map[r.fieldName] = r.source(); } });
                return map;
            }
            var v = this.value();
            (Array.isArray(v) ? v : []).forEach(function (r) {
                if (r && r.fieldName && r.source) { map[r.fieldName] = r.source; }
            });
            return map;
        },

        _makeRow: function (name, label, source, missing) {
            return {fieldName: name, label: label || name, source: ko.observable(source || ''), missing: !!missing};
        },

        /** Template fields first (template order), then mapped fields the template no longer has. */
        _buildRows: function (fields) {
            var map = this._currentMap();
            var seen = {};
            var rows = [];
            var self = this;
            fields.forEach(function (f) {
                if (!f || !f.name || seen[f.name]) { return; }
                seen[f.name] = true;
                rows.push(self._makeRow(f.name, f.label || f.display || f.name, map[f.name] || '', false));
            });
            Object.keys(map).forEach(function (name) {
                if (!seen[name]) {
                    rows.push(self._makeRow(name, name, map[name], self.fieldsLoaded()));
                }
            });
            this.rows(rows);
        },

        onTemplateChange: function (templateName) {
            templateName = templateName || '';
            if (templateName === this._lastLoadedTemplate) { return; }
            this._lastLoadedTemplate = templateName;
            this.hasTemplate(!!templateName);
            this.fieldsError('');
            this.fieldsLoaded(false);
            this.baseTemplate('');
            if (templateName) {
                this._loadFields(templateName);
            } else {
                this._buildRows([]);
            }
        },

        reload: function () {
            if (this._lastLoadedTemplate && !this.loadingFields()) {
                this.fieldsLoaded(false);
                this._loadFields(this._lastLoadedTemplate);
            }
        },

        _loadFields: function (templateName) {
            if (!this.endpointFormFields) { return; }
            var self = this;
            self.loadingFields(true);
            proxyPost(self.endpointFormFields, {templateName: templateName}).then(function (res) {
                if (templateName !== self._lastLoadedTemplate) { return; } // template changed meanwhile
                self.loadingFields(false);
                if (res.ok && res.data && Array.isArray(res.data.formFields)) {
                    self.baseTemplate(typeof res.data.baseTemplate === 'string' ? res.data.baseTemplate : '');
                    self.fieldsLoaded(true);
                    self._buildRows(res.data.formFields);
                } else {
                    self.fieldsError(res.error || 'Could not load the form fields of the template.');
                    self._buildRows([]);
                }
            });
        },

        addManual: function () {
            var name = (this.manualName() || '').trim();
            if (!name) { return; }
            var exists = this.rows().some(function (r) { return r.fieldName === name; });
            if (!exists) {
                this.rows.push(this._makeRow(name, name, '', false));
            }
            this.manualName('');
        },

        removeRow: function (row) {
            this.rows.remove(row);
        }
    });
});
