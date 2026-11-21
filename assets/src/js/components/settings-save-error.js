/** Known settings-save failures, shared by the main form and standalone SaveBar. */
export function settingsSaveError(response) {
    const partial = response && response.code === 'ace_rc_htaccess_write_failed' &&
        response.data && response.data.settings_saved === true;
    const error = new Error(partial
        ? 'Settings were saved, but the Apache cache rules could not be updated. Your existing rules were left in place. Check file ownership and directory permissions, then save again.'
        : 'Save failed. Please try again.');
    error.isSettingsSaveError = true;
    error.settingsSaved = !!partial;
    return error;
}

export function settingsSaveErrorMessage(error, fallback) {
    return error && error.isSettingsSaveError === true ? error.message : fallback;
}
