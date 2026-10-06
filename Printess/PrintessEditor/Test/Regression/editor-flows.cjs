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
const save = () => {};
editor.openFromProduct({
    templateName: 'Book', saveTemplateCallback: save,
    predefinedFormFields: [{ name: 'COVER_TYPE', value: 'Hard' }],
    namePrompt: { enabled: true, text: '<Project>' }
});
assert.equal(captured.saveTemplateCallback, save, 'product/project save callback must reach the panel');
assert.deepEqual(plain(captured.formFields), [{ name: 'COVER_TYPE', value: 'Hard' }]);
assert.doesNotThrow(() => editor.promptProjectName('Existing project'));

// Exercise the naming modal, including escaping configurable prompt text.
let overlay;
const input = { focus() {}, addEventListener() {}, value: 'My Book' };
const buttons = {};
const modalContext = {
    define: (deps, factory) => { editor = factory({}, {}); },
    window: { addEventListener() {} }, capture: cfg => { captured = cfg; },
    document: {
        createElement() {
            overlay = {
                style: {}, querySelector(selector) {
                    if (selector === '#printess-project-name-input') return input;
                    return buttons[selector] ||= { addEventListener(type, callback) { this[type] = callback; } };
                }
            };
            return overlay;
        },
        body: { appendChild() {}, removeChild() {} }
    },
    setTimeout: callback => { callback(); }, clearTimeout() {}
};
vm.runInNewContext(source.replace('openPanelEditor(panelCfg);', 'capture(panelCfg);'), modalContext);
editor.openFromProduct({namePrompt: {enabled: true, text: '<Project>'}});
const named = editor.promptProjectName('');
assert.ok(overlay.innerHTML.includes('&lt;Project&gt;'));
buttons['#printess-name-save'].click();
named.then(name => {
    assert.equal(name, 'My Book');
    console.log('Passed: save callback forwarding, predefined fields, naming modal and HTML escaping.');
}).catch(error => { console.error(error); process.exitCode = 1; });

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
    const opts = {templateName: 'Book', magicPhotobookTheme: 'berlin', formFields: [{name: 'SIZE', value: 'A4'}], saveTemplateCallback: save};
    await integration.test.open(opts);
    assert.deepEqual(plain(loads[0].formFields), [...opts.formFields, {name: 'PHOTOBOOK_THEME', value: 'berlin'}]);
    assert.equal(typeof loads[0].saveTemplateCallback, 'function');
    assert.equal('magicPhotobookTheme' in loads[0], false);
    await integration.test.open(opts);
    assert.equal(switches.length, 0, 'reopen must retain in-progress changes');
    await integration.test.open({...opts, magicPhotobookTheme: 'paris'});
    assert.equal(switches[0][2][1].value, 'paris', 'same-template grouped child theme applied');
    const savedFields = [{name: 'PHOTOBOOK_THEME', value: 'customer-theme'}];
    await integration.test.open({...opts, templateName: 'st:saved', restoreSavedDesign: true, formFields: savedFields, mergeTemplate: 'Cover'});
    assert.deepEqual(plain(switches[1][2]), savedFields, 'saved theme retained');
    assert.deepEqual(plain(switches[1][1]), [], 'saved artwork must not have initial merges reapplied');
    assert.equal(switches[1][6], 'published');
    console.log('Passed: panel loader fields/callbacks, grouped theme switching, reopen and saved-artwork preservation.');
})().catch(error => { console.error(error); process.exitCode = 1; });
