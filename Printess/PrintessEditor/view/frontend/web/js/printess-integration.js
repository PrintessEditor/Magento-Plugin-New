/**
 * Printess Editor integration for Magento.
 * Supports both Panel UI (fullscreen) and Slim UI (inline).
 */
define(['jquery'], function ($) {
    'use strict';

    var PANEL_LOADER_URL = 'https://editor.printess.com/printess-editor/loader.js';
    var SLIM_LOADER_URL = 'https://editor.printess.com/slim-ui.js';

    var _panelLoaderPromise = null;
    var _slimApi = null;   // slim UI instance
    var _slimFormId = null;   // form to submit on "Add to Basket"
    var _panelEditorRef = null;   // active panel editor reference
    var _panelHistoryPushed = false;  // true while editor history state is on the stack
    var _currentPageCount = 0;      // last page count reported by priceChangeCallback
    var _currentFormFields = {};     // live snapshot of Printess form field values

    // Close the panel editor programmatically (called by back-button handler).
    function closePanelEditor() {
        _panelHistoryPushed = false;
        var ref = _panelEditorRef;
        _panelEditorRef = null;
        if (!ref) return;
        try {
            if (typeof ref.close === 'function') { ref.close(); return; }
            if (typeof ref.hide === 'function') { ref.hide(); return; }
            if (ref.ui && typeof ref.ui.close === 'function') { ref.ui.close(); return; }
            if (ref.ui && typeof ref.ui.hide === 'function') { ref.ui.hide(); return; }
        } catch (e) { }
        // Fallback: remove any fixed full-viewport Printess overlay from the DOM.
        document.querySelectorAll('[id*="printess"],[class*="printess"]').forEach(function (el) {
            var s = window.getComputedStyle(el);
            if (s.position === 'fixed' && parseInt(s.zIndex, 10) > 999) { el.remove(); }
        });
    }

    // Intercept the browser back button while the panel editor is open.
    window.addEventListener('popstate', function () {
        if (_panelHistoryPushed) { closePanelEditor(); }
    });

    // --- variant sync helpers ---

    function getSelectedVariantLabel(variantSync) {
        if (!variantSync) return null;
        var swatch = document.querySelector(
            '.swatch-attribute[data-attribute-id="' + variantSync.attributeId + '"] .swatch-option.selected'
        );
        var optionId = swatch ? swatch.getAttribute('data-option-id') : null;

        if (!optionId) {
            var sel = document.getElementById('attribute' + variantSync.attributeId);
            optionId = sel ? sel.value : null;
        }

        if (!optionId) return null;

        var opts = variantSync.options;
        for (var label in opts) {
            if (opts[label] === optionId) return label;
        }
        return null;
    }

    function resolveOptionId(variantSync, value) {
        var opts = variantSync.options;
        var lower = (value || '').toLowerCase();

        // 1. Exact label match
        if (opts[value]) return opts[value];

        // 2. Case-insensitive label match
        for (var k in opts) {
            if (k.toLowerCase() === lower) return opts[k];
        }

        // 3. Match against DOM swatch labels/titles (covers translated option labels
        //    that may differ from the canonical Printess value)
        var attrEl = document.querySelector(
            '.swatch-attribute[data-attribute-id="' + variantSync.attributeId + '"]'
        );
        if (attrEl) {
            var swatches = attrEl.querySelectorAll('.swatch-option');
            for (var i = 0; i < swatches.length; i++) {
                var sw = swatches[i];
                var domLabel = (
                    sw.getAttribute('data-option-label') ||
                    sw.getAttribute('option-label') ||
                    sw.getAttribute('title') ||
                    sw.textContent || ''
                ).trim().toLowerCase();
                if (domLabel === lower) return sw.getAttribute('data-option-id');
            }
        }

        // 4. Incoming value is itself an option ID
        for (var k in opts) {
            if (opts[k] === value) return value;
        }

        return null; // caller falls back to first option
    }

    function setVariantInMagento(variantSync, value) {
        if (!variantSync) return;
        var attrId = variantSync.attributeId;
        var optionId = resolveOptionId(variantSync, value);

        // Swatch renderer
        var attrEl = document.querySelector('.swatch-attribute[data-attribute-id="' + attrId + '"]');
        if (attrEl) {
            var target = optionId
                ? attrEl.querySelector('.swatch-option[data-option-id="' + optionId + '"]')
                : attrEl.querySelector('.swatch-option'); // fall back to first
            if (target) { target.click(); return; }
        }

        // Select element fallback
        var sel = document.getElementById('attribute' + attrId);
        if (sel) {
            sel.value = optionId || (sel.options[0] ? sel.options[0].value : '');
            sel.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    function findMatchingCustomOption(customOptions, fieldName) {
        var lower = (fieldName || '').toLowerCase();
        for (var i = 0; i < customOptions.length; i++) {
            if ((customOptions[i].title || '').toLowerCase() === lower) return customOptions[i];
        }
        return null;
    }

    function setCustomOptionInMagento(customOption, value) {
        var sel = document.getElementById('select_' + customOption.optionId);
        if (!sel) return;
        var lower = (value || '').toLowerCase();
        var fallbackId = null;
        for (var i = 0; i < customOption.values.length; i++) {
            var v = customOption.values[i];
            if (i === 0) fallbackId = v.id;
            if (v.label.toLowerCase() === lower) {
                sel.value = v.id;
                sel.dispatchEvent(new Event('change', { bubbles: true }));
                return;
            }
        }
        if (fallbackId !== null) {
            sel.value = fallbackId;
            sel.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    function buildAutoFormFields(variantOptions, customOptions) {
        var fields = [];
        (variantOptions || []).forEach(function (attr) {
            var selected = getSelectedVariantLabel(attr);
            if (selected) fields.push({ name: attr.label, value: selected });
        });
        (customOptions || []).forEach(function (opt) {
            var sel = document.getElementById('select_' + opt.optionId);
            if (!sel || !sel.value) return;
            var selectedId = String(sel.value);
            for (var i = 0; i < opt.values.length; i++) {
                if (String(opt.values[i].id) === selectedId) {
                    fields.push({ name: opt.title, value: opt.values[i].label });
                    break;
                }
            }
        });
        console.warn('[Printess] seeding formFields', fields);
        return fields;
    }

    function findMatchingVariantAttr(variantOptions, fieldName, fieldLabel) {
        var nameLower = (fieldName || '').toLowerCase();
        var labelLower = (fieldLabel || '').toLowerCase();
        for (var i = 0; i < variantOptions.length; i++) {
            var attrLabel = (variantOptions[i].label || '').toLowerCase();
            if (attrLabel === nameLower || (labelLower && attrLabel === labelLower)) {
                return variantOptions[i];
            }
        }
        return null;
    }

    function getPanelLoader() {
        if (!_panelLoaderPromise) {
            _panelLoaderPromise = import(PANEL_LOADER_URL);
        }
        return _panelLoaderPromise;
    }

    function getBasketId() {
        var key = 'printess_basket_id';
        try {
            var id = localStorage.getItem(key);
            if (!id) {
                id = 'magento-' + Math.random().toString(36).slice(2) + '-' + Date.now();
                localStorage.setItem(key, id);
            }
            return id;
        } catch (e) {
            return 'magento-' + Date.now();
        }
    }

    // --- saved projects helpers ---

    var SAVE_URL = '/printess/project/save';
    var LIST_URL = '/printess/project/list';

    function esc(str) {
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function buildModalOverlay() {
        var overlay = document.createElement('div');
        overlay.style.cssText = [
            'position:fixed', 'top:0', 'left:0', 'width:100%', 'height:100%',
            'background:rgba(0,0,0,.55)', 'z-index:100000',
            'display:flex', 'align-items:center', 'justify-content:center'
        ].join(';');
        return overlay;
    }

    function buildModal(titleText, bodyHtml, footerHtml) {
        var box = document.createElement('div');
        box.style.cssText = [
            'background:#fff', 'border-radius:6px', 'padding:28px 32px',
            'min-width:360px', 'max-width:560px', 'width:90vw',
            'max-height:80vh', 'display:flex', 'flex-direction:column', 'gap:16px'
        ].join(';');
        box.innerHTML = '<h3 style="margin:0;font-size:18px;font-weight:600;">' + esc(titleText) + '</h3>'
            + '<div class="pm-body" style="overflow-y:auto;flex:1;">' + bodyHtml + '</div>'
            + '<div class="pm-footer" style="display:flex;gap:8px;justify-content:flex-end;">' + footerHtml + '</div>';
        return box;
    }

    function btn(label, primary) {
        var s = 'padding:8px 18px;border-radius:4px;border:1px solid #ccc;cursor:pointer;font-size:14px;';
        if (primary) s += 'background:#1979c3;color:#fff;border-color:#1979c3;';
        else s += 'background:#fff;color:#333;';
        return '<button style="' + s + '">' + esc(label) + '</button>';
    }

    function buildSaveCallback() {
        return function (saveToken) {
            return new Promise(function (resolve, reject) {
                var overlay = buildModalOverlay();
                var modal = buildModal(
                    'Save Project',
                    '<label style="display:block;margin-bottom:6px;font-size:14px;">Project name</label>'
                    + '<input id="pm-name" type="text" placeholder="My Project" style="width:100%;box-sizing:border-box;padding:8px;border:1px solid #ccc;border-radius:4px;font-size:14px;" />',
                    btn('Cancel') + btn('Save', true)
                );
                overlay.appendChild(modal);
                document.body.appendChild(overlay);

                var input = modal.querySelector('#pm-name');
                var buttons = modal.querySelectorAll('button');
                input.focus();

                function close() { overlay.remove(); }

                buttons[0].addEventListener('click', function () { close(); reject(new Error('cancelled')); });
                buttons[1].addEventListener('click', function () {
                    var name = input.value.trim() || 'My Project';
                    close();
                    fetch(SAVE_URL, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({ name: name, saveToken: saveToken })
                    })
                        .then(function (r) {
                            if (r.status === 401) {
                                alert('Please log in to save your project.');
                                reject(new Error('not logged in'));
                                return null;
                            }
                            return r.json();
                        })
                        .then(function (d) {
                            if (!d) return;
                            d.success ? resolve() : reject(new Error(d.message || 'Save failed'));
                        })
                        .catch(reject);
                });
                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') buttons[1].click();
                    if (e.key === 'Escape') buttons[0].click();
                });
            });
        };
    }

    function buildLoadCallback() {
        return function () {
            return new Promise(function (resolve, reject) {
                fetch(LIST_URL, { credentials: 'same-origin' })
                    .then(function (r) {
                        if (r.status === 401) {
                            alert('Please log in to load a saved project.');
                            reject(new Error('not logged in'));
                            return null;
                        }
                        return r.json();
                    })
                    .then(function (projects) {
                        if (!projects) return;
                        var overlay = buildModalOverlay();
                        var listHtml;
                        if (!projects.length) {
                            listHtml = '<p style="color:#888;margin:0;">No saved projects yet.</p>';
                        } else {
                            listHtml = '<ul style="list-style:none;margin:0;padding:0;">';
                            projects.forEach(function (p, i) {
                                var date = p.created_at ? p.created_at.replace('T', ' ').slice(0, 16) : '';
                                listHtml += '<li data-index="' + i + '" style="'
                                    + 'padding:10px 12px;cursor:pointer;border-radius:4px;'
                                    + 'display:flex;justify-content:space-between;align-items:center;'
                                    + 'border-bottom:1px solid #f0f0f0;">'
                                    + '<span style="font-weight:500;">' + esc(p.name) + '</span>'
                                    + '<span style="font-size:12px;color:#888;white-space:nowrap;margin-left:12px;">' + esc(date) + '</span>'
                                    + '</li>';
                            });
                            listHtml += '</ul>';
                        }
                        var modal = buildModal('My Saved Projects', listHtml, btn('Cancel'));
                        overlay.appendChild(modal);
                        document.body.appendChild(overlay);

                        function close() { overlay.remove(); }

                        modal.querySelectorAll('li[data-index]').forEach(function (li) {
                            li.addEventListener('mouseenter', function () { li.style.background = '#f5f5f5'; });
                            li.addEventListener('mouseleave', function () { li.style.background = ''; });
                            li.addEventListener('click', function () {
                                close();
                                resolve(projects[parseInt(li.dataset.index, 10)].save_token);
                            });
                        });
                        modal.querySelector('button').addEventListener('click', function () {
                            close();
                            reject(new Error('cancelled'));
                        });
                    })
                    .catch(reject);
            });
        };
    }

    // --- pricing helpers ---

    /**
     * Given a pagePricing rules array and the current form field values, return the
     * best-matching price per page.  Rules with more matching conditions win; the rule
     * with no conditions is used as the default/fallback.
     *
     * Rule format: { conditions: "fieldName=value,field2=value2", pricePerPage: 0.10 }
     * An empty/missing conditions string means "default".
     */
    function resolvePricePerPage(pagePricing, formFields) {
        if (!pagePricing || !pagePricing.length) return 0;
        var bestPrice = 0;
        var bestScore = -1;
        pagePricing.forEach(function (rule) {
            var condStr = (rule.conditions || '').trim();
            var price = parseFloat(rule.pricePerPage) || 0;
            if (condStr === '') {
                if (bestScore < 0) { bestScore = 0; bestPrice = price; }
                return;
            }
            var parts = condStr.split(',');
            var score = 0;
            var allMatch = true;
            parts.forEach(function (part) {
                var kv = part.split('=');
                if (kv.length !== 2) return;
                var key = kv[0].trim();
                var val = kv[1].trim().toLowerCase();
                var cur = ((formFields && formFields[key]) || '').toLowerCase();
                if (cur !== val) { allMatch = false; }
                else { score++; }
            });
            if (allMatch && score > bestScore) { bestScore = score; bestPrice = price; }
        });
        return bestScore >= 0 ? bestPrice : 0;
    }

    function formatPrice(price, currencyCode, locale) {
        try {
            return new Intl.NumberFormat(locale || 'en-US', {
                style: 'currency', currency: currencyCode || 'USD'
            }).format(price);
        } catch (e) {
            return price.toFixed(2);
        }
    }

    function computePrice(basePrice, pagePricing, pageCount, formFields) {
        return basePrice + pageCount * resolvePricePerPage(pagePricing, formFields);
    }

    function makePriceChangeCallback(basePrice, pagePricing, currencyCode, locale, getPanelRef) {
        return function (data) {
            var pageCount = typeof data === 'number' ? data : (data.pageCount || data.pages || 0);
            _currentPageCount = pageCount;
            var newPrice = computePrice(basePrice, pagePricing, pageCount, _currentFormFields);
            var ref = getPanelRef();
            if (ref && ref.ui && ref.ui.refreshPriceDisplay) {
                ref.ui.refreshPriceDisplay({ price: formatPrice(newPrice, currencyCode, locale) });
            }
        };
    }

    async function openPanelEditor(opts) {
        _currentPageCount = 0;
        _currentFormFields = {};

        // Seed form fields from the initial values passed to Printess so the first
        // priceChangeCallback fires with the correct field state.
        if (opts.formFields) {
            opts.formFields.forEach(function (ff) {
                _currentFormFields[ff.name] = ff.value;
            });
        }

        // Push a history entry so the back button closes the editor instead of navigating away.
        if (!_panelHistoryPushed) {
            _panelHistoryPushed = true;
            history.pushState({ printessEditor: true }, '');
        }

        var loaderModule = await getPanelLoader();
        var panelRef = null;
        var loadCfg = {
            token: opts.shopToken,
            templateName: opts.templateName,
            templateVersion: 'published',
            basketId: getBasketId(),
            addToBasketCallback: opts.onAddToBasket
        };
        if (opts.formFields && opts.formFields.length) {
            loadCfg.formFields = opts.formFields;
        }

        var variantOptions = opts.variantOptions || [];
        var customOptions  = opts.customOptions  || [];
        var fieldHandlers = [];
        if (variantOptions.length || customOptions.length) {
            fieldHandlers.push(function (fieldName, value, _tag, fieldLabel) {
                var attr = findMatchingVariantAttr(variantOptions, fieldName, fieldLabel);
                if (attr) { setVariantInMagento(attr, value); return; }
                var opt = findMatchingCustomOption(customOptions, fieldName);
                if (opt) setCustomOptionInMagento(opt, value);
            });
        }
        var pagePricing = opts.pagePricing || [];
        // Only attach our callbacks when there are pricing rules — otherwise let
        // Printess use its own internal price display so we don't break it.
        if (pagePricing.length) {
            fieldHandlers.push(function (fieldName, value) {
                _currentFormFields[fieldName] = value;
                var newPrice = computePrice(opts.basePrice || 0, pagePricing, _currentPageCount, _currentFormFields);
                if (panelRef && panelRef.ui && panelRef.ui.refreshPriceDisplay) {
                    panelRef.ui.refreshPriceDisplay({ price: formatPrice(newPrice, opts.currencyCode, opts.locale) });
                }
            });
        }
        loadCfg.formFieldChangedCallback = function (name, value, tag, label) {
            _currentFormFields[name] = value;
            var matchedAttr = findMatchingVariantAttr(variantOptions, name, label);
            var matchedOpt  = matchedAttr ? null : findMatchingCustomOption(customOptions, name);
            console.warn('[Printess] fieldChanged', { fieldName: name, value: value, variantMatch: matchedAttr ? matchedAttr.label : null, customOptionMatch: matchedOpt ? matchedOpt.title : null, customOptionsAvailable: customOptions.length });
            fieldHandlers.forEach(function (h) { h(name, value, tag, label); });
        };

        if (opts.theme) loadCfg.theme = opts.theme;
        if (opts.magicPhotobookTheme) loadCfg.magicPhotobookTheme = opts.magicPhotobookTheme;
        if (opts.printSettings) loadCfg.printSettings = opts.printSettings;
        if (opts.mergeTemplate) loadCfg.attach = { mergeTemplates: [{ templateName: opts.mergeTemplate }] };
        if (opts.saveTemplateCallback) loadCfg.saveTemplateCallback = opts.saveTemplateCallback;
        if (opts.loadTemplateButtonCallback) loadCfg.loadTemplateButtonCallback = opts.loadTemplateButtonCallback;
        loadCfg.backButtonCallback = function () {
            history.back(); // pops the state we pushed, triggering the popstate handler which closes the editor
        };
        if (pagePricing.length) {
            loadCfg.priceChangeCallback = makePriceChangeCallback(
                opts.basePrice || 0,
                pagePricing,
                opts.currencyCode,
                opts.locale,
                function () { return panelRef; }
            );
        }
        console.warn('[Printess] calling load() with loadCfg keys:', Object.keys(loadCfg), '| pagePricing:', pagePricing);
        panelRef = await loaderModule.load(loadCfg);
        _panelEditorRef = panelRef;
    }

    function setOrAddHidden(form, name, value) {
        var el = form.querySelector('[name="' + name + '"]');
        if (el) {
            el.value = value;
        } else {
            el = document.createElement('input');
            el.type = 'hidden';
            el.name = name;
            el.value = value;
            form.appendChild(el);
        }
    }

    function postFormToCart(form, saveToken, thumbnailUrl) {
        setOrAddHidden(form, 'saveToken', saveToken || '');
        setOrAddHidden(form, 'thumbnailUrl', thumbnailUrl || '');
        setOrAddHidden(form, 'printessPageCount', String(_currentPageCount || 0));
        setOrAddHidden(form, 'printessFormFields', JSON.stringify(_currentFormFields || {}));

        var formData = new FormData(form);

        return fetch(form.action, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            redirect: 'follow'
        }).then(function () {
            window.location.href = '/checkout/cart/';
        }).catch(function (err) {
            console.error('Printess: add-to-cart failed', err);
            form.submit();
        });
    }

    return {

        /**
         * Panel UI — product page "Customize" button.
         */
        openFromProduct: function (cfg) {
            var variantOptions = cfg.variantOptions || [];
            var customOptions  = cfg.customOptions  || [];
            console.warn('[Printess] openFromProduct', { variantOptions: variantOptions, customOptions: customOptions });
            var panelCfg = {
                shopToken: cfg.shopToken,
                templateName: cfg.templateName,
                formFields: buildAutoFormFields(variantOptions, customOptions),
                variantOptions: variantOptions,
                customOptions:  customOptions,
                theme: cfg.theme,
                magicPhotobookTheme: cfg.magicPhotobookTheme,
                printSettings: cfg.printSettings,
                mergeTemplate: cfg.mergeTemplate,
                pagePricing: cfg.pagePricing || [],
                currencyCode: cfg.currencyCode,
                locale: cfg.locale,
                basePrice: cfg.basePrice,
                onAddToBasket: function (saveToken, thumbnailUrl) {
                    var form = document.getElementById(cfg.formId || 'product_addtocart_form');
                    if (!form) {
                        console.error('Printess: add-to-cart form not found');
                        return Promise.reject(new Error('form not found'));
                    }
                    return postFormToCart(form, saveToken, thumbnailUrl);
                }
            };
            panelCfg.saveTemplateCallback = buildSaveCallback();
            panelCfg.loadTemplateButtonCallback = buildLoadCallback();
            openPanelEditor(panelCfg);
        },

        /**
         * Slim UI — initialise the inline editor on the product page.
         * Must be called once on page load; the "Add to Basket" button
         * should then call addToBasketFromSlim().
         */
        initSlimUi: function (cfg) {
            _slimFormId = cfg.formId || 'product_addtocart_form';
            _currentPageCount = 0;
            var variantOptions = cfg.variantOptions || [];
            var customOptions  = cfg.customOptions  || [];

            import(SLIM_LOADER_URL).then(function (slimModule) {
                var slimCfg = {
                    previewContainer: document.querySelector('.printess-preview'),
                    uiContainer: document.querySelector('.printess-ui'),
                    previewImage: document.querySelector('.printess-preview-image'),
                    loader: document.querySelector('.printess-image-loader'),
                    shopToken: cfg.shopToken,
                    templateName: cfg.templateName,
                    published: true,
                    formFields: buildAutoFormFields(variantOptions, customOptions)
                };

                if (cfg.theme) slimCfg.theme = cfg.theme;
                if (cfg.magicPhotobookTheme) slimCfg.magicPhotobookTheme = cfg.magicPhotobookTheme;
                if (cfg.printSettings) slimCfg.printSettings = cfg.printSettings;
                if (cfg.mergeTemplate) slimCfg.attach = { mergeTemplates: [{ templateName: cfg.mergeTemplate }] };

                if (variantOptions.length || customOptions.length) {
                    slimCfg.formFieldChangedCallback = function (fieldName, value, _tag, fieldLabel) {
                        var attr = findMatchingVariantAttr(variantOptions, fieldName, fieldLabel);
                        if (attr) { setVariantInMagento(attr, value); return; }
                        var opt = findMatchingCustomOption(customOptions, fieldName);
                        if (opt) setCustomOptionInMagento(opt, value);
                    };
                }

                if (cfg.pagePricing && cfg.pagePricing.length) {
                    slimCfg.priceChangeCallback = makePriceChangeCallback(
                        cfg.basePrice || 0,
                        cfg.pagePricing,
                        cfg.currencyCode,
                        cfg.locale,
                        function () { return _slimApi; }
                    );
                }

                slimModule.createSlimUi(slimCfg).then(function (api) {
                    _slimApi = api;

                    // Magento swatch → Slim UI: push newly selected swatch value into Printess.
                    if (variantOptions.length) {
                        document.addEventListener('click', function (e) {
                            var swatch = e.target.closest('.swatch-option[data-option-id]');
                            if (!swatch) return;
                            var optionId = swatch.getAttribute('data-option-id');
                            var attrEl = swatch.closest('.swatch-attribute[data-attribute-id]');
                            if (!attrEl) return;
                            var attrId = attrEl.getAttribute('data-attribute-id');
                            variantOptions.forEach(function (attr) {
                                if (attr.attributeId !== attrId) return;
                                for (var label in attr.options) {
                                    if (attr.options[label] === optionId) {
                                        if (api.setFormFieldValue) api.setFormFieldValue(attr.label, label);
                                        break;
                                    }
                                }
                            });
                        });
                    }
                    // Custom option select → Slim UI: push changed dropdown value into Printess.
                    customOptions.forEach(function (opt) {
                        var sel = document.getElementById('select_' + opt.optionId);
                        if (!sel) return;
                        sel.addEventListener('change', function () {
                            var selectedId = String(sel.value);
                            for (var i = 0; i < opt.values.length; i++) {
                                if (String(opt.values[i].id) === selectedId) {
                                    if (api.setFormFieldValue) api.setFormFieldValue(opt.title, opt.values[i].label);
                                    break;
                                }
                            }
                        });
                    });
                }).catch(function (err) {
                    console.error('Printess Slim UI init failed', err);
                });
            }).catch(function (err) {
                console.error('Printess Slim UI loader failed', err);
            });
        },

        /**
         * Slim UI — called when the customer clicks "Add to Basket".
         * Retrieves the save token from the slim UI, then posts the form.
         */
        addToBasketFromSlim: function () {
            if (!_slimApi) {
                console.error('Printess: Slim UI not ready');
                return;
            }

            var form = document.getElementById(_slimFormId || 'product_addtocart_form');
            if (!form) {
                console.error('Printess: add-to-cart form not found');
                return;
            }

            _slimApi.createSaveToken().then(function (data) {
                postFormToCart(form, data.saveToken, data.thumbnailUrl || '');
            }).catch(function (err) {
                console.error('Printess: createSaveToken failed', err);
            });
        },

        /**
         * Panel UI — cart "Customize" button (re-open editor for existing item).
         */
        openFromCart: function (cfg) {
            openPanelEditor({
                shopToken: cfg.shopToken,
                templateName: cfg.saveToken,
                onAddToBasket: function (newSaveToken, newThumbnailUrl) {
                    return new Promise(function (resolve, reject) {
                        $.ajax({
                            url: cfg.updateUrl,
                            method: 'POST',
                            data: {
                                itemId: cfg.itemId,
                                saveToken: newSaveToken,
                                thumbnailUrl: newThumbnailUrl || '',
                                form_key: cfg.formKey
                            },
                            success: function (response) {
                                if (response && response.success) {
                                    resolve();
                                    window.location.reload();
                                } else {
                                    reject(new Error('update failed'));
                                    alert('Could not update your customisation. Please try again.');
                                }
                            },
                            error: function () {
                                reject(new Error('network error'));
                                alert('Could not update your customisation. Please try again.');
                            }
                        });
                    });
                }
            });
        }
    };
});
