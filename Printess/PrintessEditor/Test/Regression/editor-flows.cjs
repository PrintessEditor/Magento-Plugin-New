// Run from the Magento root: node app/code/Printess/PrintessEditor/Test/Regression/editor-flows.cjs
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const assert = require('assert/strict');
const source = fs.readFileSync(path.join(__dirname, '../../view/frontend/web/js/printess-integration.js'), 'utf8');
let editor, captured;
// Capture the public product launch boundary without loading the remote SDK.
vm.runInNewContext(source.replace('openPanelEditor(panelCfg);', 'capture(panelCfg);'), {
    define: (deps, factory) => { editor = factory({}, {}); },
    window: { addEventListener() {} },
    capture: cfg => { captured = cfg; }
});
const plain = value => JSON.parse(JSON.stringify(value));
editor.openFromProduct({
    templateName: 'Book', projectId: 7,
    predefinedFormFields: [{ name: 'COVER_TYPE', value: 'Hard' }],
    mergeTemplates: [{ templateName: 'Cover', mergeMode: 'merge' }]
});
assert.deepEqual(plain(captured.formFields), [{ name: 'COVER_TYPE', value: 'Hard' }]);
assert.deepEqual(plain(captured.mergeTemplates), [{ templateName: 'Cover', mergeMode: 'merge' }]);
assert.equal(captured.projectId, 7, 'project id must reach the panel for native save/load');
assert.equal(captured.restoreSavedDesign, false);
assert.doesNotThrow(() => editor.promptProjectName('Existing project'));
console.log('Passed: product launch config (predefined fields, merge templates, project id).');

// Exercise actual loader configuration and persistent editor switches with only SDK/price dependencies stubbed.
(async () => {
    let integration;
    const instrumented = source.replace('        promptProjectName: promptProjectName,', `
        test: {
            open: openPanelEditor,
            setup: function(loader) {
                _panelLoaderPromise = Promise.resolve(loader);
                getBasketId = function() { return 'test'; };
                resolveMinPagesFromApi = async function() { return 0; };
                getLivePriceInfoFromApi = async function() { return null; };
                refreshEditorPrice = function() {};
            }
        },
        promptProjectName: promptProjectName,`);
    vm.runInNewContext(instrumented, {
        define: (deps, factory) => { integration = factory({}, {}); },
        window: {addEventListener() {}}, history: {pushState() {}}, console
    });
    const loads = [], switches = [];
    const panel = {ui: {show() {}}, api: {async loadTemplateAndFormFields(...args) {switches.push(args);}}};
    integration.test.setup({async load(cfg) {loads.push(cfg); return panel;}});
    const merges = [{templateName: 'Cover', mergeMode: 'merge'}];
    const opts = {templateName: 'Book', magicPhotobookTheme: 'berlin', formFields: [{name: 'SIZE', value: 'A4'}], mergeTemplates: merges};
    await integration.test.open(opts);
    assert.deepEqual(plain(loads[0].formFields), [...opts.formFields, {name: 'PHOTOBOOK_THEME', value: 'berlin'}]);
    for (const cb of ['getShopDataCallback', 'getShopSavedDataCallback', 'isShopUserLoggedInCallback', 'getShopProjectDisplayNameCallback', 'shopLoginCallback']) {
        assert.equal(typeof loads[0][cb], 'function', cb + ' (native save/load) must be wired');
    }
    assert.equal('saveTemplateCallback' in loads[0], false, 'native save/load must not be overridden');
    assert.deepEqual(plain(loads[0].attach), {mergeTemplates: merges});
    assert.equal('magicPhotobookTheme' in loads[0], false);
    await integration.test.open(opts);
    assert.equal(switches.length, 0, 'reopen must retain in-progress changes');
    await integration.test.open({...opts, magicPhotobookTheme: 'paris'});
    assert.equal(switches[0][2][1].value, 'paris', 'same-template grouped child theme applied');
    assert.deepEqual(plain(switches[0][1]), merges, 'theme switch keeps merge templates');
    const savedFields = [{name: 'PHOTOBOOK_THEME', value: 'customer-theme'}];
    await integration.test.open({...opts, templateName: 'st:saved', restoreSavedDesign: true, formFields: savedFields});
    assert.deepEqual(plain(switches[1][2]), savedFields, 'saved theme retained');
    assert.deepEqual(plain(switches[1][1]), [], 'saved artwork must not have initial merges reapplied');
    assert.equal(switches[1][6], 'published');
    console.log('Passed: panel loader fields/native callbacks/merges, grouped theme switching, reopen and saved-artwork preservation.');
})().catch(error => { console.error(error); process.exitCode = 1; });

// Keep the real price calculation and API parsing for the account-page reopen flow.
(async () => {
    let integration;
    const instrumented = source.replace('        promptProjectName: promptProjectName,', `
        test: {
            open: openPanelEditor,
            close: closePanelEditor,
            setup: function(loader) {
                _panelLoaderPromise = Promise.resolve(loader);
                getBasketId = function() { return 'test'; };
                resolveMinPagesFromApi = async function() { return 0; };
            }
        },
        promptProjectName: promptProjectName,`);
    vm.runInNewContext(instrumented, {
        define: (deps, factory) => { integration = factory({}, {}); },
        window: {addEventListener() {}}, history: {pushState() {}},
        document: {querySelector() { return null; }},
        setTimeout: callback => { callback(); }, console
    });
    const prices = [], switches = [];
    let pageCount = 30, loads = 0, hides = 0;
    const panel = {
        ui: {
            show() {}, hide() { hides++; },
            refreshPriceDisplay(data) { prices.push(data.price); }
        },
        api: {
            getPriceRelevantData() { return {pageCount, priceRelevantFormFields: {}}; },
            async loadTemplateAndFormFields(...args) { switches.push(args); }
        }
    };
    integration.test.setup({async load() { loads++; return panel; }});
    const opts = {
        templateName: 'st:saved', restoreSavedDesign: true,
        basePrice: 28, pagePricing: [{conditions: '', pricePerPage: 0.30}],
        currencyCode: 'GBP', locale: 'en-GB'
    };
    await integration.test.open(opts);
    assert.equal(prices.at(-1), '£37.00', 'first open includes page charges');
    integration.test.close();
    await integration.test.open(opts);
    assert.equal(prices.at(-1), '£37.00', 'same-project reopen must retain page charges');
    assert.equal(loads, 1, 'reopen reuses the loaded editor');
    assert.equal(hides, 1);
    assert.equal(switches.length, 0, 'same-project reopen must not reload artwork');
    pageCount = 40;
    await integration.test.open({...opts, templateName: 'st:another'});
    assert.equal(prices.at(-1), '£40.00', 'switching projects reads the new page count');
    pageCount = 0;
    await integration.test.open({...opts, templateName: 'st:empty'});
    assert.equal(prices.at(-1), '£28.00', 'zero-page state must not reuse another project count');
    console.log('Passed: account project pricing on first open, close/reopen and project switches.');
})().catch(error => { console.error(error); process.exitCode = 1; });
