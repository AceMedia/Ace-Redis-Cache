/* Actual SaveBar/admin methods with a disposable jQuery transport. No WordPress or network. */
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const root = path.join(__dirname, '..', 'assets', 'src', 'js');
let transport;
const jq = () => ({ ready() {}, is: () => false });
jq.ajax = options => transport(options);
const context = vm.createContext({ window: { jQuery: jq }, jQuery: jq, document: { visibilityState: 'visible' }, console: { log() {}, error() {} }, setTimeout() { return 1; }, clearTimeout() { context.clearedTimers = (context.clearedTimers || 0) + 1; }, ace_redis_admin: { rest_url: '/fixture/', rest_nonce: 'fixture', nonce: 'fixture' } });
vm.runInContext(fs.readFileSync(path.join(root, 'components/settings-save-error.js'), 'utf8').replaceAll('export function ', 'function '), context);
vm.runInContext(fs.readFileSync(path.join(root, 'components/SaveBar.js'), 'utf8').replace(/^import .*;$/gm, '').replace('export default SaveBar;', 'globalThis.TestSaveBar = SaveBar;'), context);
vm.runInContext(fs.readFileSync(path.join(root, 'admin.js'), 'utf8').replace(/^import .*;$/gm, '').replace('    // Initialize when document is ready', '    globalThis.TestAdmin = AceRedisCacheAdmin;\n    // Initialize when document is ready'), context);
let checks = 0;
const partial = { code: 'ace_rc_htaccess_write_failed', data: { status: 500, settings_saved: true } };
const detail = 'Settings were saved, but the Apache cache rules could not be updated.';
function admin() {
    const item = Object.create(context.TestAdmin.prototype);
    Object.assign(item, { getFormDataObject: () => ({}), collectManagedPlugins: () => ({}), captureOriginalFormData() { this.captured = true; } });
    return item;
}
function bar(onSave) {
    const item = Object.create(context.TestSaveBar.prototype);
    Object.assign(item, { options: { onSave, containerSelector: '#fixture' }, hasUnsavedChanges: true, isSaving: false, setSaving(value) { this.isSaving = value; }, getFormDataObject: () => ({}), showMessage(message, type) { this.last = { message, type }; }, setSuccess(value) { this.success = value; }, captureOriginalFormData() {}, setUnsavedChanges(value) { this.hasUnsavedChanges = value; }, applyServerSettings(value) { this.applied = value; this.hasUnsavedChanges = false; }, notifySavedSettings(value) { this.notified = value; } });
    return item;
}
(async () => {
    for (const method of ['handleSave', 'handleAutoSave']) {
        for (const useAdmin of [true, false]) {
            for (const reply of ['partial-500', 'partial-200', 'network', 'bad-json', 'success']) {
                const response = reply === 'success' ? { success: true, data: { settings: { enabled: 1 } } } : reply.startsWith('partial') ? partial : null;
                transport = options => reply === 'partial-500' || reply === 'network' ? options.error({ responseJSON: response }) : options.success(response);
                const form = admin();
                const item = bar(useAdmin ? () => form.saveSettingsViaSaveBar() : null);
                await item[method]();
                if (reply === 'success') {
                    assert.equal(item.last.type, 'success'); assert.equal(item.applied.enabled, 1); assert.equal(item.notified.enabled, 1); assert.equal(item.hasUnsavedChanges, false);
                } else {
                    assert.equal(item.last.type, 'error'); assert.equal(item.hasUnsavedChanges, true); assert.equal(item.success, undefined); assert.equal(item.notified, undefined); assert.equal(form.captured, undefined);
                    assert.equal(item.last.message.startsWith(detail), reply.startsWith('partial'));
                }
                assert.equal(item.isSaving, false);
                ++checks;
            }
        }
    }
    // A partial save must remain retryable when the server succeeds on the next attempt.
    const form = admin(); const retry = bar(() => form.saveSettingsViaSaveBar());
    transport = options => options.error({ responseJSON: partial }); await retry.handleSave();
    transport = options => options.success({ success: true, data: { settings: { enabled: 1 } } }); await retry.handleSave();
    assert.equal(retry.last.type, 'success'); assert.equal(retry.hasUnsavedChanges, false); ++checks;
    // Only the recognised partial-save shape may claim that the database settings saved.
    for (const payload of [{ ...partial, data: { settings_saved: false } }, { code: 'other', data: { settings_saved: true } }, { ...partial, message: '<script>bad</script>' }]) {
        const error = context.settingsSaveError(payload);
        assert.equal(error.settingsSaved, payload.code === partial.code && payload.data.settings_saved === true);
        assert.equal(error.message.includes('<script>'), false); ++checks;
    }
    // The pending elapsed timer and an older success timeout must not replace a save error.
    const persistent = Object.create(context.TestSaveBar.prototype);
    Object.assign(persistent, { messageTimer: 99, stopElapsedTimeTracking() { this.stopped = true; }, updateMessageDisplay(type) { this.displayType = type; } });
    persistent.showMessage(detail, 'error');
    assert.equal(persistent.stopped, true); assert.equal(persistent.messageTimer, null);
    assert.equal(persistent.message, detail); assert.equal(persistent.displayType, 'error'); assert.equal(context.clearedTimers, 1); ++checks;
    console.log(JSON.stringify({ scenarios: checks, passed: true, network: false, productionMethods: true }));
})().catch(error => { console.error(error); process.exitCode = 1; });
