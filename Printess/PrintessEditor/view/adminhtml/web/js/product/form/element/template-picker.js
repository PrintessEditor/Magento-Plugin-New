define([
    'Magento_Ui/js/form/element/abstract',
    'ko',
    'jquery'
], function (Abstract, ko, $) {
    'use strict';

    var PRINTESS_API_BASE = 'https://api.printess.com';

    /**
     * POST JSON to a Magento admin proxy endpoint.
     * Returns a Promise<{ok, status, data, error}> — never throws.
     */
    function proxyPost(url, data) {
        if (!url) {
            return Promise.resolve({ ok: false, status: 0, data: null, error: 'Endpoint URL not configured.' });
        }
        // Admin POST requests require the session form key as a query param
        // (Content-Type: application/json bodies are not parsed by Magento's getParam()).
        // window.FORM_KEY is set globally in every admin page via require_js.phtml.
        // isAjax=1 ensures Magento returns JSON (not an HTML redirect) when validation fails.
        var formKey = (typeof window !== 'undefined' && window.FORM_KEY) ? window.FORM_KEY : '';
        var sep = url.indexOf('?') >= 0 ? '&' : '?';
        var fullUrl = url + sep + 'isAjax=1' + (formKey ? '&form_key=' + encodeURIComponent(formKey) : '');

        return fetch(fullUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin',
            body: JSON.stringify(data || {})
        }).then(function (r) {
            var status = r.status;
            return r.text().then(function (text) {
                try {
                    var parsed = JSON.parse(text);
                    // Magento returns {error: true, message: "..."} for CSRF/auth failures with isAjax=1
                    if (parsed && parsed.error === true && typeof parsed.message === 'string') {
                        return { ok: false, status: status, data: null, error: parsed.message };
                    }
                    return { ok: r.ok, status: status, data: parsed, error: null };
                } catch (e) {
                    var hint = (text.indexOf('login') !== -1 || text.indexOf('Login') !== -1)
                        ? 'Admin session may have expired — reload the page and try again.'
                        : 'Unexpected non-JSON response (HTTP ' + status + ').';
                    return { ok: false, status: status, data: null, error: hint };
                }
            });
        }, function (networkErr) {
            return { ok: false, status: 0, data: null, error: 'Network error: ' + networkErr.message };
        });
    }

    function flattenDirs(node, depth, result) {
        result = result || [];
        if (!node) { return result; }
        result.push({ id: node.id, name: node.n, depth: depth });
        (node.c || []).slice().sort(function (a, b) { return a.n.localeCompare(b.n); })
            .forEach(function (child) { flattenDirs(child, depth + 1, result); });
        return result;
    }

    return Abstract.extend({
        defaults: {
            template: 'ui/form/field',
            elementTmpl: 'Printess_PrintessEditor/product/form/element/template-picker',
            endpointTemplates:   '',
            endpointDirectories: '',
            endpointTags:        '',
            endpointKeywords:    '',
            endpointSnippets:    '',
            showSnippetsTab:     true
        },

        initialize: function () {
            this._super();

            // ── shared ──────────────────────────────────────────────────
            this.pickerOpen    = ko.observable(false);
            this.activeTab     = ko.observable('templates');
            this.globalError   = ko.observable('');

            // ── templates tab ────────────────────────────────────────────
            this.directories      = ko.observableArray([]);
            this.selectedDirId    = ko.observable(null);
            this.templates        = ko.observableArray([]);
            this.templateFilter   = ko.observable('');
            this.selectedTemplate = ko.observable(null);
            this.loadingTemplates = ko.observable(false);
            this.templatesError   = ko.observable('');

            this.filteredTemplates = ko.computed(function () {
                var filter = this.templateFilter().toLowerCase().trim();
                return this.templates().filter(function (t) {
                    return !filter || t.name.toLowerCase().indexOf(filter) !== -1;
                });
            }, this);

            // ── snippets tab ─────────────────────────────────────────────
            this.tags             = ko.observableArray([]);
            this.keywords         = ko.observableArray([]);
            this.selectedTags     = ko.observableArray([]);
            this.selectedKeywords = ko.observableArray([]);
            this.snippets         = ko.observableArray([]);
            this.selectedSnippet  = ko.observable(null);
            this.loadingTags      = ko.observable(false);
            this.loadingSnippets  = ko.observable(false);
            this.tagsError        = ko.observable('');
            this.snippetsError    = ko.observable('');

            // ── confirm state ─────────────────────────────────────────────
            this.canConfirm = ko.computed(function () {
                if (this.activeTab() === 'templates') { return this.selectedTemplate() !== null; }
                return this.selectedSnippet() !== null;
            }, this);

            return this;
        },

        // ── Public actions ────────────────────────────────────────────────

        openPicker: function () {
            // Log endpoints so we can verify they are set correctly
            console.info('[Printess picker] opening. endpointTags:', this.endpointTags);

            this.pickerOpen(true);
            this.activeTab('templates');
            this.globalError('');
            this.selectedTemplate(null);
            this.selectedSnippet(null);
            this._loadDirectories();
            this._loadTemplates(null);
        },

        closePicker: function () {
            this.pickerOpen(false);
        },

        confirmSelection: function () {
            if (!this.canConfirm()) { return; }
            if (this.activeTab() === 'templates') {
                this.value(this.selectedTemplate().name);
            } else {
                this.value('sid~' + this.selectedSnippet().id);
            }
            this.pickerOpen(false);
        },

        selectDirectory: function (dirId) {
            this.selectedDirId(dirId);
            this._loadTemplates(dirId);
        },

        selectTemplate: function (tpl) {
            this.selectedTemplate(tpl);
        },

        selectSnippet: function (snippet) {
            this.selectedSnippet(snippet);
        },

        switchTab: function (tabName) {
            this.activeTab(tabName);
            if (tabName === 'snippets' && this.tags().length === 0 && !this.loadingTags()) {
                this._loadTags();
            }
        },

        toggleTag: function (tag) {
            var idx = this.selectedTags.indexOf(tag);
            if (idx === -1) {
                this.selectedTags.push(tag);
            } else {
                this.selectedTags.splice(idx, 1);
            }
            this.selectedKeywords([]);
            this.snippets([]);
            if (this.selectedTags().length > 0) {
                this._loadSnippets();
            }
        },

        toggleKeyword: function (kw) {
            var idx = this.selectedKeywords.indexOf(kw);
            if (idx === -1) {
                this.selectedKeywords.push(kw);
            } else {
                this.selectedKeywords.splice(idx, 1);
            }
            this._loadSnippets();
        },

        // ── Private loaders ───────────────────────────────────────────────

        _loadDirectories: function () {
            var self = this;
            proxyPost(self.endpointDirectories, {}).then(function (res) {
                if (res.ok && res.data && !res.data.error) {
                    self.directories(flattenDirs(res.data, 0, []));
                } else if (res.error) {
                    console.warn('[Printess picker] directories error:', res.error);
                }
            });
        },

        _loadTemplates: function (dirId) {
            var self = this;
            self.loadingTemplates(true);
            self.templatesError('');
            self.templates([]);
            var payload = {};
            if (dirId !== null && dirId !== undefined) { payload.directoryId = dirId; }

            proxyPost(self.endpointTemplates, payload).then(function (res) {
                if (!res.ok || res.error) {
                    self.templatesError(res.error || ('HTTP ' + res.status));
                } else if (res.data && Array.isArray(res.data.ts)) {
                    var list = res.data.ts
                        .filter(function (t) { return t.hpv; })
                        .sort(function (a, b) { return a.n.localeCompare(b.n); })
                        .map(function (t) {
                            return { name: t.n, thumbnailUrl: t.turl || '', id: t.id };
                        });
                    self.templates(list);
                } else {
                    self.templatesError('Unexpected response format.');
                    console.warn('[Printess picker] templates unexpected data:', res.data);
                }
                self.loadingTemplates(false);
            });
        },

        _loadTags: function () {
            var self = this;
            console.info('[Printess picker] loading tags from:', self.endpointTags);
            self.loadingTags(true);
            self.tagsError('');

            proxyPost(self.endpointTags, {}).then(function (res) {
                console.info('[Printess picker] tags response:', res);
                if (!res.ok || res.error) {
                    self.tagsError(res.error || ('HTTP ' + res.status));
                } else if (Array.isArray(res.data)) {
                    var list = res.data
                        .map(function (t) { return (typeof t === 'object') ? t.tag : t; })
                        .filter(Boolean)
                        .sort();
                    self.tags(list);
                    if (list.length === 0) {
                        self.tagsError('No tags found in your Printess account.');
                    }
                    // Pre-load keywords as well
                    self._loadKeywords();
                } else {
                    self.tagsError('Unexpected response. Check that the Printess service token is configured.');
                    console.warn('[Printess picker] tags unexpected data:', res.data);
                }
                self.loadingTags(false);
            });
        },

        _loadKeywords: function () {
            var self = this;
            proxyPost(self.endpointKeywords, {}).then(function (res) {
                if (res.ok && Array.isArray(res.data)) {
                    self.keywords(res.data.filter(Boolean).sort());
                }
            });
        },

        _loadSnippets: function () {
            var self = this;
            if (self.selectedTags().length === 0) { self.snippets([]); return; }
            self.loadingSnippets(true);
            self.snippetsError('');

            proxyPost(self.endpointSnippets, {
                tags:     self.selectedTags(),
                keywords: self.selectedKeywords()
            }).then(function (res) {
                if (!res.ok || res.error) {
                    self.snippetsError(res.error || ('HTTP ' + res.status));
                } else if (Array.isArray(res.data)) {
                    self.snippets(res.data.map(function (s) {
                        return { id: s.id, name: s.title || '', thumbnailUrl: s.iurl || '' };
                    }));
                } else {
                    self.snippetsError('Unexpected response format.');
                }
                self.loadingSnippets(false);
            });
        }
    });
});
