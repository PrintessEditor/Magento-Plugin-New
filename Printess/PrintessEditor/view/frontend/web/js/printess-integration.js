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
    var _minPages = 0;               // template's minimum page count (minSpreads * 2); pages at or below this are not billed
    var _minPagesResolved = false;   // true when included pages are known (from template info or first page count)
    var _slimCartContext = null;     // fallback add-to-cart data used when product form is missing
    var _currentTemplateName = '';   // active Printess template for API calls
    var _currentShopToken = '';      // active Printess shop token for API calls
    var _selectedOptionPrices = {};  // optionId -> price of currently selected value (used when no Magento price box)
    var _activePanelOpts = null;     // mutable ref to the current panel config — callbacks read from this so re-opening a different project works correctly
    var _panelLoadedTemplate = '';   // save token currently loaded in the persistent editor instance

    var _cartLoaderOverlay = null;

    function showCartLoader(message) {
        if (_cartLoaderOverlay) { return; }
        var overlay = document.createElement('div');
        overlay.className = 'printess-owned';
        Object.assign(overlay.style, {
            position: 'fixed', inset: '0', zIndex: '2147483646',
            display: 'flex', alignItems: 'center', justifyContent: 'center',
            background: 'rgba(0,0,0,0.55)'
        });
        overlay.innerHTML =
            '<div style="display:flex;flex-direction:column;align-items:center;gap:16px;">' +
              '<style>' +
              '@keyframes printess-loader-rotate{100%{transform:rotate(360deg)}}' +
              '@keyframes printess-loader-dash{0%{stroke-dasharray:1,200;stroke-dashoffset:0}50%{stroke-dasharray:89,200;stroke-dashoffset:-35}100%{stroke-dasharray:89,200;stroke-dashoffset:-124}}' +
              '</style>' +
              '<div style="width:68px;height:68px;">' +
                '<svg viewBox="25 25 50 50" style="animation:printess-loader-rotate 2s linear infinite;height:100%;width:100%;">' +
                  '<circle cx="50" cy="50" r="20" fill="none" stroke="#fff" stroke-width="5" stroke-miterlimit="10"' +
                  ' style="stroke-dasharray:1,200;stroke-dashoffset:0;animation:printess-loader-dash 1.5s ease-in-out infinite;stroke-linecap:round;"/>' +
                '</svg>' +
              '</div>' +
              (message ? '<span style="color:#fff;font-family:system-ui,sans-serif;font-size:15px;font-weight:500;">' + message + '</span>' : '') +
            '</div>';
        document.body.appendChild(overlay);
        _cartLoaderOverlay = overlay;
    }

    function hideCartLoader() {
        if (_cartLoaderOverlay) {
            document.body.removeChild(_cartLoaderOverlay);
            _cartLoaderOverlay = null;
        }
    }

    /**
     * Show a modal overlay above the Printess editor asking for a project name.
     * Returns a Promise that resolves with the entered name (empty string if skipped).
     * If existingName is provided (non-empty), the prompt is skipped entirely and
     * resolves immediately — the project already has a name.
     * Uses printess-owned class so the Printess CSS doesn't hide it.
     */
    function promptProjectName(existingName) {
        if (existingName && existingName.trim() !== '') {
            return Promise.resolve('');
        }
        return new Promise(function (resolve) {
            var overlay = document.createElement('div');
            overlay.className = 'printess-owned';
            Object.assign(overlay.style, {
                position: 'fixed', inset: '0', zIndex: '2147483647',
                display: 'flex', alignItems: 'center', justifyContent: 'center',
                background: 'rgba(0,0,0,0.6)'
            });

            overlay.innerHTML =
                '<div style="background:#fff;border-radius:8px;padding:32px;width:380px;max-width:90vw;' +
                'box-shadow:0 8px 32px rgba(0,0,0,0.3);font-family:system-ui,sans-serif;">' +
                  '<h3 style="margin:0 0 8px;font-size:18px;font-weight:600;color:#1a1a1a;">Name your project</h3>' +
                  '<p style="margin:0 0 20px;font-size:14px;color:#555;">Give this design a name so you can find it easily later.</p>' +
                  '<input id="printess-project-name-input" type="text" maxlength="255" placeholder="e.g. My Wedding Album"' +
                  ' style="width:100%;box-sizing:border-box;padding:10px 12px;font-size:15px;border:1px solid #ccc;' +
                  'border-radius:5px;outline:none;margin-bottom:20px;" />' +
                  '<div style="display:flex;gap:12px;justify-content:flex-end;">' +
                    '<button id="printess-name-skip" style="padding:9px 20px;border:1px solid #ccc;background:#fff;' +
                    'border-radius:5px;font-size:14px;cursor:pointer;color:#555;">Skip</button>' +
                    '<button id="printess-name-save" style="padding:9px 20px;background:#1a73e8;color:#fff;border:none;' +
                    'border-radius:5px;font-size:14px;font-weight:600;cursor:pointer;">Save name</button>' +
                  '</div>' +
                '</div>';

            document.body.appendChild(overlay);

            var input = overlay.querySelector('#printess-project-name-input');
            var saveBtn = overlay.querySelector('#printess-name-save');
            var skipBtn = overlay.querySelector('#printess-name-skip');

            function done(name) {
                document.body.removeChild(overlay);
                resolve(name || '');
            }

            saveBtn.addEventListener('click', function () { done(input.value.trim()); });
            skipBtn.addEventListener('click', function () { done(''); });
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { done(input.value.trim()); }
                if (e.key === 'Escape') { done(''); }
            });

            setTimeout(function () { input.focus(); }, 50);
        });
    }

    // Close the panel editor programmatically.
    // Per Printess docs: close with printess.ui.hide() and reopen with printess.ui.show().
    // We keep _panelEditorRef set so the next openPanelEditor call uses show() instead of
    // a fresh load(). Removing the element (el.remove()) corrupts Magento's require.js
    // module queue and prevents the project-edit callback from firing on subsequent opens.
    function closePanelEditor() {
        _panelHistoryPushed = false;
        if (!_panelEditorRef) return;
        try {
            if (_panelEditorRef.ui && typeof _panelEditorRef.ui.hide === 'function') {
                _panelEditorRef.ui.hide();
                // Keep _panelEditorRef set — show() will reuse this instance
                return;
            }
            if (typeof _panelEditorRef.hide === 'function') {
                _panelEditorRef.hide();
                return;
            }
        } catch (e) { }
        // hide not available — fall back to DOM removal
        try {
            var el = _panelEditorRef.ui || _panelEditorRef;
            if (el && typeof el.remove === 'function') { el.remove(); }
        } catch (e) { }
        _panelEditorRef = null;
        _panelLoadedTemplate = '';
    }

    // Intercept the browser back button while the panel editor is open.
    // backButtonCallback uses replaceState (not history.back()) so this handler
    // is only needed for the physical browser back button during an open editor session.
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

    async function getLivePriceInfoFromApi(api) {
        if (!api) return null;
        try {
            var target = api;
            if (target && typeof target.getPriceRelevantData !== 'function' && target.api) {
                target = target.api;
            }
            if (!target || typeof target.getPriceRelevantData !== 'function') {
                return null;
            }

            var info = target.getPriceRelevantData();
            if (info && typeof info.then === 'function') {
                info = await info;
            }
            if (!info || typeof info !== 'object') return null;

            var formFields = {};
            var src = info.priceRelevantFormFields || {};
            Object.keys(src).forEach(function (k) {
                formFields[k] = String((src[k] && src[k].value) || '');
            });

            var pageCount = null;
            if (typeof info.pageCount !== 'undefined') {
                pageCount = Number(info.pageCount);
            } else if (typeof info.pages !== 'undefined') {
                pageCount = Number(info.pages);
            } else if (typeof info.page_count !== 'undefined') {
                pageCount = Number(info.page_count);
            } else if (typeof info.numberOfPages !== 'undefined') {
                pageCount = Number(info.numberOfPages);
            } else if (typeof info.spreads !== 'undefined') {
                pageCount = Number(info.spreads) * 2;
            }

            if (pageCount !== null && (isNaN(pageCount) || pageCount < 0)) {
                pageCount = null;
            }

            return { pageCount: pageCount, formFields: formFields };
        } catch (e) {
            return null;
        }
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

    function normalizeFieldKey(value) {
        return String(value || '').trim().toLowerCase().replace(/\s+/g, ' ');
    }

    function normalizeConditionKey(value) {
        return String(value || '')
            .trim()
            .toUpperCase()
            .replace(/[^A-Z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '');
    }

    function findFormFieldValue(formFields, fieldName) {
        if (!formFields || typeof formFields !== 'object') return '';
        if (Object.prototype.hasOwnProperty.call(formFields, fieldName)) {
            return String(formFields[fieldName] || '');
        }
        var target = normalizeFieldKey(fieldName);
        var targetCondKey = normalizeConditionKey(fieldName);
        for (var k in formFields) {
            if (normalizeFieldKey(k) === target || normalizeConditionKey(k) === targetCondKey) {
                return String(formFields[k] || '');
            }
        }
        return '';
    }

    function findMatchingCustomOption(customOptions, fieldName, fieldLabel) {
        var nameKey = normalizeFieldKey(fieldName);
        var labelKey = normalizeFieldKey(fieldLabel);
        var nameCondKey = normalizeConditionKey(fieldName);
        var labelCondKey = normalizeConditionKey(fieldLabel);
        for (var i = 0; i < customOptions.length; i++) {
            var titleKey = normalizeFieldKey(customOptions[i].title);
            var titleCondKey = normalizeConditionKey(customOptions[i].title);
            if (
                titleKey === nameKey ||
                (labelKey && titleKey === labelKey) ||
                titleCondKey === nameCondKey ||
                (labelCondKey && titleCondKey === labelCondKey)
            ) {
                return customOptions[i];
            }
        }
        return null;
    }

    function normalizeOptionValue(value) {
        return String(value || '')
            .trim()
            .toLowerCase()
            .replace(/\s+/g, '')
            .replace(/[^a-z0-9x._-]/g, '');
    }

    function getCustomOptionValueId(customOption, value, tag) {
        var rawValue = String(value || '');
        var rawTag = String(tag || '');
        var lowerValue = rawValue.toLowerCase();
        var lowerTag = rawTag.toLowerCase();
        var normalizedValue = normalizeOptionValue(rawValue);
        var normalizedTag = normalizeOptionValue(rawTag);
        for (var i = 0; i < customOption.values.length; i++) {
            var v = customOption.values[i];
            var label = String(v.label || '');
            var lowerLabel = label.toLowerCase();
            var normalizedLabel = normalizeOptionValue(label);
            if (
                String(v.id) === rawValue ||
                String(v.id) === rawTag ||
                lowerLabel === lowerValue ||
                lowerLabel === lowerTag ||
                normalizedLabel === normalizedValue ||
                normalizedLabel === normalizedTag
            ) {
                return String(v.id);
            }
        }
        return null;
    }

    function setCustomOptionInMagento(customOption, value, tag) {
        var sel = document.getElementById('select_' + customOption.optionId);
        var selectedId = getCustomOptionValueId(customOption, value, tag);
        if (!sel) return selectedId;
        if (selectedId !== null) {
            sel.value = selectedId;
            sel.dispatchEvent(new Event('change', { bubbles: true }));
            return selectedId;
        }
        return selectedId;
    }

    // Track the price of the currently selected value for a custom option.
    // Used to compute the correct base price when no Magento price box is present (e.g. account area).
    function trackOptionPrice(customOption, selectedId) {
        var price = 0;
        if (selectedId !== null) {
            for (var i = 0; i < customOption.values.length; i++) {
                if (String(customOption.values[i].id) === String(selectedId)) {
                    price = parseFloat(customOption.values[i].price) || 0;
                    break;
                }
            }
        }
        _selectedOptionPrices[customOption.optionId] = price;
    }

    function computeOptionUpcharge() {
        return Object.keys(_selectedOptionPrices).reduce(function (sum, k) {
            return sum + (_selectedOptionPrices[k] || 0);
        }, 0);
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
        // console.warn('[Printess] seeding formFields', fields);
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

    async function resolveMinPagesFromApi(api, templateName, shopToken) {
        if (!api) return null;
        try {
            var target = api;
            if (target && typeof target.getDocInfoForPhotobook !== 'function' && target.api) {
                target = target.api;
            }
            if (!target || typeof target.getDocInfoForPhotobook !== 'function') {
                return null;
            }
            var info = target.getDocInfoForPhotobook(templateName, shopToken);
            if (info && typeof info.then === 'function') {
                info = await info;
            }
            if (info && typeof info.minSpreads === 'number' && !isNaN(info.minSpreads)) {
                return Math.max(0, info.minSpreads * 2 - 2);
            }
        } catch (e) { }
        return null;
    }

    // --- saved projects helpers ---

    function esc(str) {
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
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
                var cur = findFormFieldValue(formFields, key).toLowerCase();
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
        var billablePages = Math.max(0, pageCount - _minPages);
        return basePrice + billablePages * resolvePricePerPage(pagePricing, formFields);
    }

    function refreshEditorPrice(ref, basePrice, pagePricing, currencyCode, locale) {
        if (!ref || !ref.ui || !ref.ui.refreshPriceDisplay) return;
        var currentBase = readMagentoFinalPrice(basePrice || 0);
        var newPrice = computePrice(currentBase, pagePricing || [], _currentPageCount, _currentFormFields);
        ref.ui.refreshPriceDisplay({ price: formatPrice(newPrice, currencyCode, locale) });

        // Magento price-box updates can occur a tick after option change handlers.
        setTimeout(function () {
            var delayedBase = readMagentoFinalPrice(basePrice || 0);
            var delayedPrice = computePrice(delayedBase, pagePricing || [], _currentPageCount, _currentFormFields);
            ref.ui.refreshPriceDisplay({ price: formatPrice(delayedPrice, currencyCode, locale) });
        }, 0);
    }

    // Read the current Magento final price from the product page price box.
    // Magento's price-box widget updates data-price-amount synchronously when a
    // custom option or configurable attribute change event fires.
    function readMagentoFinalPrice(fallback) {
        var box = document.querySelector('.product-info-main [data-role="priceBox"], [data-role="priceBox"]');
        if (box && window.jQuery) {
            var $box = window.jQuery(box);
            try {
                var widget = $box.data('magePriceBox') || $box.data('mage-priceBox');
                var display = widget && widget.cache && widget.cache.displayPrices && widget.cache.displayPrices.finalPrice;
                if (display) {
                    var finalAmount = parseFloat(typeof display.final !== 'undefined' ? display.final : display.amount);
                    if (!isNaN(finalAmount) && finalAmount > 0) return finalAmount;
                }
                if ($box.priceBox && $box.priceBox('option') && $box.priceBox('option').prices) {
                    var optPrices = $box.priceBox('option').prices;
                    if (optPrices.finalPrice && typeof optPrices.finalPrice.amount !== 'undefined') {
                        var optAmount = parseFloat(optPrices.finalPrice.amount);
                        if (!isNaN(optAmount) && optAmount > 0) return optAmount;
                    }
                }
            } catch (e) { }
        }

        var el = document.querySelector('.product-info-main [data-price-type="finalPrice"], [data-role="priceBox"] [data-price-type="finalPrice"]');
        if (el) {
            var attrAmount = parseFloat(el.getAttribute('data-price-amount'));
            if (!isNaN(attrAmount) && attrAmount > 0) return attrAmount;
            var textAmount = parseFloat(String(el.textContent || '').replace(/[^0-9.,-]/g, '').replace(',', '.'));
            if (!isNaN(textAmount) && textAmount > 0) return textAmount;
        }
        return fallback + computeOptionUpcharge();
    }

    function makePriceChangeCallback(basePrice, pagePricing, currencyCode, locale, getPanelRef, customOptions) {
        return function (data) {
            var pageCount = typeof data === 'number' ? data : (
                data.pageCount ||
                data.pages ||
                data.page_count ||
                data.numberOfPages ||
                (typeof data.spreads !== 'undefined' ? Number(data.spreads) * 2 : 0) ||
                0
            );
            _currentPageCount = pageCount;
            if (!_minPagesResolved && pageCount > 0) {
                _minPages = pageCount;
                _minPagesResolved = true;
            }

            if (data && typeof data === 'object' && data.priceRelevantFormFields) {
                Object.keys(data.priceRelevantFormFields).forEach(function (k) {
                    _currentFormFields[k] = data.priceRelevantFormFields[k].value;
                });
                // Seed option prices from the current form field state so the initial
                // price display is correct when opening a saved project (account area).
                var activeCustomOptions = (_activePanelOpts && _activePanelOpts.customOptions) || customOptions;
                if (activeCustomOptions && activeCustomOptions.length) {
                    activeCustomOptions.forEach(function (opt) {
                        var fieldValue = findFormFieldValue(_currentFormFields, opt.title);
                        if (fieldValue) {
                            trackOptionPrice(opt, getCustomOptionValueId(opt, fieldValue));
                        }
                    });
                }
            }

            var currentOpts = _activePanelOpts || { basePrice: basePrice, pagePricing: pagePricing, currencyCode: currencyCode, locale: locale, customOptions: customOptions };
            var currentBase = readMagentoFinalPrice(currentOpts.basePrice || basePrice);
            var newPrice = computePrice(currentBase, currentOpts.pagePricing || pagePricing, pageCount, _currentFormFields);
            var ref = getPanelRef();
            if (ref && ref.ui && ref.ui.refreshPriceDisplay) {
                ref.ui.refreshPriceDisplay({ price: formatPrice(newPrice, currentOpts.currencyCode || currencyCode, currentOpts.locale || locale) });
            }
        };
    }

    async function openPanelEditor(opts) {
        _currentTemplateName = opts.templateName || '';
        _currentShopToken = opts.shopToken || '';
        _activePanelOpts = opts; // always update so callbacks dispatch to the current project

        // _panelEditorRef is kept set after hide() — reuse the existing instance
        // via show() + loadTemplate() rather than a fresh load().
        if (_panelEditorRef) {
            _activePanelOpts = opts;
            try {
                var ui  = _panelEditorRef.ui  || _panelEditorRef;
                var api = _panelEditorRef.api || _panelEditorRef;

                if (ui && typeof ui.show === 'function') {
                    if (!_panelHistoryPushed) { _panelHistoryPushed = true; history.pushState({ printessEditor: true }, ''); }

                    _currentPageCount = 0;
                    _currentFormFields = {};
                    _selectedOptionPrices = {};
                    _minPages = 0;
                    _minPagesResolved = false;

                    ui.show();

                    if (opts.templateName && opts.templateName !== _panelLoadedTemplate) {
                        if (api && typeof api.loadTemplate === 'function') {
                            await api.loadTemplate(opts.templateName);
                            _panelLoadedTemplate = opts.templateName;
                        }
                    }

                    // Seed initial form fields from the template's current state (same
                    // as the fresh-load path — priceChangeCallback won't fire on show/loadTemplate).
                    var reuseInfo = await getLivePriceInfoFromApi(api);
                    if (reuseInfo && reuseInfo.formFields && Object.keys(reuseInfo.formFields).length) {
                        // Build field handlers inline (reuse path doesn't have fieldHandlers in scope).
                        var reuseOpts = _activePanelOpts || opts;
                        Object.keys(reuseInfo.formFields).forEach(function (name) {
                            var value = reuseInfo.formFields[name];
                            if (value === '' || value === undefined) return;
                            _currentFormFields[name] = value;
                            var reuseVariantOptions = reuseOpts.variantOptions || [];
                            var reuseCustomOptions  = reuseOpts.customOptions  || [];
                            var attr = findMatchingVariantAttr(reuseVariantOptions, name, name);
                            if (attr) { setVariantInMagento(attr, value); return; }
                            var opt = findMatchingCustomOption(reuseCustomOptions, name, name);
                            if (opt) {
                                var selectedId = setCustomOptionInMagento(opt, value, null);
                                trackOptionPrice(opt, selectedId);
                            }
                        });
                    }

                    refreshEditorPrice(_panelEditorRef, opts.basePrice || 0, opts.pagePricing || [], opts.currencyCode, opts.locale);
                    return;
                }
                if (typeof _panelEditorRef.show === 'function') {
                    if (!_panelHistoryPushed) { _panelHistoryPushed = true; history.pushState({ printessEditor: true }, ''); }
                    _panelEditorRef.show();
                    return;
                }
            } catch (e) { }
            // Couldn't show — clear the stale ref and fall through to a fresh load
            _panelEditorRef = null;
        }

        _currentPageCount = 0;
        _currentFormFields = {};
        _selectedOptionPrices = {};
        _minPages = 0;
        _minPagesResolved = false;

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
            addToBasketCallback: function (saveToken, thumbnailUrl) {
                // Read from _activePanelOpts so this callback always dispatches
                // to the project that is currently open, even after a template swap.
                var activeOpts = _activePanelOpts || opts;
                return activeOpts.onAddToBasket(saveToken, thumbnailUrl, panelRef && panelRef.api ? panelRef.api : null);
            }
        };
        if (opts.formFields && opts.formFields.length) {
            loadCfg.formFields = opts.formFields;
        }

        var variantOptions = opts.variantOptions || [];
        var customOptions = opts.customOptions || [];
        var fieldHandlers = [];
        if (variantOptions.length || customOptions.length) {
            fieldHandlers.push(function (fieldName, value, tag, fieldLabel) {
                var activeOpts = _activePanelOpts || opts;
                var currentVariantOptions = activeOpts.variantOptions || [];
                var currentCustomOptions  = activeOpts.customOptions  || [];
                var attr = findMatchingVariantAttr(currentVariantOptions, fieldName, fieldLabel);
                if (attr) { setVariantInMagento(attr, value); return; }
                var opt = findMatchingCustomOption(currentCustomOptions, fieldName, fieldLabel);
                if (opt) {
                    var selectedId = setCustomOptionInMagento(opt, value, tag);
                    trackOptionPrice(opt, selectedId);
                }
            });
        }
        var pagePricing = opts.pagePricing || [];
        fieldHandlers.push(function (fieldName, value) {
            _currentFormFields[fieldName] = value;
            var activeOpts = _activePanelOpts || opts;
            refreshEditorPrice(panelRef, activeOpts.basePrice || 0, activeOpts.pagePricing || [], activeOpts.currencyCode, activeOpts.locale);
        });
        loadCfg.formFieldChangedCallback = function (name, value, tag, label) {
            _currentFormFields[name] = value;
            fieldHandlers.forEach(function (h) { h(name, value, tag, label); });
        };

        if (opts.theme) loadCfg.theme = opts.theme;
        if (opts.magicPhotobookTheme) loadCfg.magicPhotobookTheme = opts.magicPhotobookTheme;
        if (opts.printSettings) loadCfg.printSettings = opts.printSettings;
        if (opts.mergeTemplate) loadCfg.attach = { mergeTemplates: [{ templateName: opts.mergeTemplate }] };
        if (opts.saveTemplateCallback) {
            loadCfg.saveTemplateCallback = function (saveToken, type, thumbnailUrl) {
                var activeOpts = _activePanelOpts || opts;
                if (activeOpts.saveTemplateCallback) {
                    return activeOpts.saveTemplateCallback(saveToken, type, thumbnailUrl);
                }
            };
        }
        if (opts.loadTemplateButtonCallback) loadCfg.loadTemplateButtonCallback = opts.loadTemplateButtonCallback;
        loadCfg.backButtonCallback = function () {
            var wasHistoryPushed = _panelHistoryPushed;
            closePanelEditor(); // calls hide(), keeps _panelEditorRef set for reuse
            if (wasHistoryPushed) {
                // Neutralise the extra history state we pushed without calling history.back().
                // history.back() triggers a popstate that Magento's router intercepts, which
                // silently drops the pending require.js callback on the next openEditor call.
                // replaceState overwrites the pushed entry with a neutral state — no popstate
                // event fires, no navigation occurs, and the require.js queue is unaffected.
                history.replaceState(null, '', window.location.href);
            }
        };
        loadCfg.priceChangeCallback = makePriceChangeCallback(
            opts.basePrice || 0,
            pagePricing,
            opts.currencyCode,
            opts.locale,
            function () { return panelRef; },
            customOptions
        );
        panelRef = await loaderModule.load(loadCfg);
        _panelEditorRef = panelRef;
        _panelLoadedTemplate = opts.templateName || '';
        var resolvedMinPages = await resolveMinPagesFromApi(
            panelRef && panelRef.api ? panelRef.api : panelRef,
            opts.templateName,
            opts.shopToken
        );
        if (typeof resolvedMinPages === 'number') {
            _minPages = resolvedMinPages;
            _minPagesResolved = true;
        }

        // Seed initial form field state from Printess's current values.
        // priceChangeCallback only fires on user-driven changes, not on initial load,
        // so _currentFormFields and _selectedOptionPrices stay empty until the user
        // manually changes a field. Querying getPriceRelevantData() immediately after
        // load gives us the template defaults (e.g. DOCUMENT_SIZE, COVER_TYPE) so the
        // displayed price is correct from the moment the editor opens.
        var liveInfo = await getLivePriceInfoFromApi(panelRef && panelRef.api ? panelRef.api : panelRef);
        if (liveInfo && liveInfo.formFields && Object.keys(liveInfo.formFields).length) {
            Object.keys(liveInfo.formFields).forEach(function (name) {
                var value = liveInfo.formFields[name];
                if (value === '' || value === undefined) return;
                _currentFormFields[name] = value;
                // Run through field handlers: syncs Magento option selections and
                // populates _selectedOptionPrices so the upcharge is included in the price.
                fieldHandlers.forEach(function (h) { h(name, value, null, name); });
            });
        }
        if (liveInfo && liveInfo.pageCount !== null && !_minPagesResolved) {
            _currentPageCount = liveInfo.pageCount;
        }

        // Trigger an immediate price update so the display reflects the current Magento price
        // and the now-correct Printess field state.
        refreshEditorPrice(panelRef, opts.basePrice || 0, pagePricing, opts.currencyCode, opts.locale);
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

    function ensureMagentoOptionInputs(form, variantOptions, customOptions) {
        (variantOptions || []).forEach(function (attr) {
            var selectedId = null;
            var swatch = document.querySelector(
                '.swatch-attribute[data-attribute-id="' + attr.attributeId + '"] .swatch-option.selected'
            );
            if (swatch) selectedId = swatch.getAttribute('data-option-id');
            if (!selectedId) {
                var selectEl = document.getElementById('attribute' + attr.attributeId);
                if (selectEl && selectEl.value) selectedId = String(selectEl.value);
            }
            if (!selectedId) {
                var fieldValue = findFormFieldValue(_currentFormFields, attr.label);
                selectedId = resolveOptionId(attr, fieldValue);
            }
            if (selectedId) {
                setOrAddHidden(form, 'super_attribute[' + attr.attributeId + ']', String(selectedId));
            }
        });

        (customOptions || []).forEach(function (opt) {
            var selectedId = null;
            var selectEl = document.getElementById('select_' + opt.optionId);
            if (selectEl && selectEl.value) {
                selectedId = String(selectEl.value);
            }
            if (!selectedId) {
                var fieldValue = findFormFieldValue(_currentFormFields, opt.title);
                selectedId = getCustomOptionValueId(opt, fieldValue);
            }
            if (selectedId) {
                setOrAddHidden(form, 'options[' + opt.optionId + ']', String(selectedId));
            }
        });
    }

    function resolveFormKey(explicitFormKey) {
        if (explicitFormKey) return explicitFormKey;
        if (window.FORM_KEY) return window.FORM_KEY;
        var m = document.cookie.match(/(?:^|;\s*)form_key=([^;]+)/);
        return m ? decodeURIComponent(m[1]) : '';
    }

    function getOrCreateCartForm(cfg) {
        var formId = cfg.formId || 'product_addtocart_form';
        var form = document.getElementById(formId);
        if (form) return form;
        if (!cfg.addToCartUrl) return null;

        var detachedId = formId + '_printess_detached';
        form = document.getElementById(detachedId);
        if (!form) {
            form = document.createElement('form');
            form.id = detachedId;
            form.method = 'post';
            form.action = cfg.addToCartUrl;
            form.style.display = 'none';
            document.body.appendChild(form);
        }

        var formKey = resolveFormKey(cfg.formKey);
        if (formKey) setOrAddHidden(form, 'form_key', formKey);
        if (cfg.productId) setOrAddHidden(form, 'product', String(cfg.productId));
        setOrAddHidden(form, 'qty', '1');

        return form;
    }

    async function postFormToCart(form, saveToken, thumbnailUrl, variantOptions, customOptions, apiRef) {
        var live = await getLivePriceInfoFromApi(apiRef);
        var effectivePageCount = _currentPageCount || 0;
        var effectiveFormFields = _currentFormFields || {};

        if (live) {
            if (live.pageCount !== null && !isNaN(live.pageCount) && live.pageCount > 0) {
                effectivePageCount = live.pageCount;
                _currentPageCount = live.pageCount;
            }
            if (live.formFields && typeof live.formFields === 'object') {
                effectiveFormFields = live.formFields;
                _currentFormFields = live.formFields;
            }
        }

        var effectiveIncludedPages = (_minPages || 0);
        var liveIncludedPages = await resolveMinPagesFromApi(apiRef, _currentTemplateName, _currentShopToken);
        if (typeof liveIncludedPages === 'number') {
            effectiveIncludedPages = liveIncludedPages;
            _minPages = liveIncludedPages;
            _minPagesResolved = true;
        }

        ensureMagentoOptionInputs(form, variantOptions, customOptions);
        setOrAddHidden(form, 'saveToken', saveToken || '');
        setOrAddHidden(form, 'thumbnailUrl', thumbnailUrl || '');
        setOrAddHidden(form, 'printessPageCount', String(effectivePageCount));
        setOrAddHidden(form, 'printessIncludedPages', String(effectiveIncludedPages));
        setOrAddHidden(form, 'printessFormFields', JSON.stringify(effectiveFormFields));

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

        promptProjectName: promptProjectName,
        showCartLoader: showCartLoader,
        hideCartLoader: hideCartLoader,

        /**
         * Panel UI — product page "Customize" button.
         */
        openFromProduct: function (cfg) {
            var variantOptions = cfg.variantOptions || [];
            var customOptions = cfg.customOptions || [];
            // console.warn('[Printess] openFromProduct', { variantOptions: variantOptions, customOptions: customOptions });
            var panelCfg = {
                shopToken: cfg.shopToken,
                templateName: cfg.templateName,
                formFields: buildAutoFormFields(variantOptions, customOptions),
                variantOptions: variantOptions,
                customOptions: customOptions,
                theme: cfg.theme,
                magicPhotobookTheme: cfg.magicPhotobookTheme,
                printSettings: cfg.printSettings,
                mergeTemplate: cfg.mergeTemplate,
                pagePricing: cfg.pagePricing || [],
                currencyCode: cfg.currencyCode,
                locale: cfg.locale,
                basePrice: cfg.basePrice,
                onAddToBasket: async function (saveToken, thumbnailUrl, apiRef) {
                    var form = getOrCreateCartForm({
                        formId: cfg.formId || 'product_addtocart_form',
                        addToCartUrl: cfg.addToCartUrl || '',
                        productId: cfg.productId || '',
                        formKey: cfg.formKey || ''
                    });
                    if (!form) {
                        console.error('Printess: add-to-cart form not found');
                        throw new Error('form not found');
                    }
                    if (cfg.onAddToBasket) {
                        try { await cfg.onAddToBasket(saveToken, thumbnailUrl); } catch (e) {}
                    }
                    return postFormToCart(form, saveToken, thumbnailUrl, variantOptions, customOptions, apiRef);
                }
            };
            panelCfg.saveTemplateCallback = cfg.saveTemplateCallback || buildSaveCallback();
            openPanelEditor(panelCfg);
        },

        /**
         * Slim UI — initialise the inline editor on the product page.
         * Must be called once on page load; the "Add to Basket" button
         * should then call addToBasketFromSlim().
         */
        initSlimUi: function (cfg) {
            _currentTemplateName = cfg.templateName || '';
            _currentShopToken = cfg.shopToken || '';
            _slimFormId = cfg.formId || 'product_addtocart_form';
            _slimCartContext = {
                formId: _slimFormId,
                addToCartUrl: cfg.addToCartUrl || '',
                productId: cfg.productId || '',
                formKey: cfg.formKey || '',
                variantOptions: cfg.variantOptions || [],
                customOptions: cfg.customOptions || []
            };
            _currentPageCount = 0;
            _minPages = 0;
            _minPagesResolved = false;
            var variantOptions = cfg.variantOptions || [];
            var customOptions = cfg.customOptions || [];

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
                    slimCfg.formFieldChangedCallback = function (fieldName, value, tag, fieldLabel) {
                        var attr = findMatchingVariantAttr(variantOptions, fieldName, fieldLabel);
                        if (attr) { setVariantInMagento(attr, value); return; }
                        var opt = findMatchingCustomOption(customOptions, fieldName, fieldLabel);
                        if (opt) {
                            var selectedId = setCustomOptionInMagento(opt, value, tag);
                            trackOptionPrice(opt, selectedId);
                        }
                    };
                }

                slimCfg.priceChangeCallback = makePriceChangeCallback(
                    cfg.basePrice || 0,
                    cfg.pagePricing || [],
                    cfg.currencyCode,
                    cfg.locale,
                    function () { return _slimApi; },
                    customOptions
                );

                slimModule.createSlimUi(slimCfg).then(async function (api) {
                    _slimApi = api;
                    var resolvedMinPages = await resolveMinPagesFromApi(api, cfg.templateName, cfg.shopToken);
                    if (typeof resolvedMinPages === 'number') {
                        _minPages = resolvedMinPages;
                        _minPagesResolved = true;
                    }

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

            var form = getOrCreateCartForm({
                formId: _slimFormId || 'product_addtocart_form',
                addToCartUrl: (_slimCartContext && _slimCartContext.addToCartUrl) || '',
                productId: (_slimCartContext && _slimCartContext.productId) || '',
                formKey: (_slimCartContext && _slimCartContext.formKey) || ''
            });
            if (!form) {
                console.error('Printess: add-to-cart form not found');
                return;
            }

            _slimApi.createSaveToken().then(function (data) {
                postFormToCart(
                    form,
                    data.saveToken,
                    data.thumbnailUrl || '',
                    (_slimCartContext && _slimCartContext.variantOptions) || [],
                    (_slimCartContext && _slimCartContext.customOptions) || [],
                    _slimApi
                );
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
