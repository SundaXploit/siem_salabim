import './bootstrap';

import Alpine from 'alpinejs';
import { alertTable } from './live-alerts';
import { alertEvidence } from './alert-evidence';
import { notificationComposer, notificationTemplatesManager } from './notification-composer';
import { refreshIcons } from './ui/icons';
import { initializeMotion } from './ui/motion';
import { initializeDialogs } from './ui/dialogs';
import { applyTheme, initializeTheme, toggleTheme } from './ui/theme';
import { initializeSessionActivity } from './session-activity';

window.Alpine = Alpine;
window.alertTable = alertTable;
window.alertEvidence = alertEvidence;
window.notificationComposer = notificationComposer;
window.notificationTemplatesManager = notificationTemplatesManager;
window.SIEMUI = { refreshIcons };
window.applyTheme = applyTheme;
window.toggleTheme = toggleTheme;

Alpine.start();

const initializeUi = () => {
    initializeTheme();
    refreshIcons();
    initializeMotion();
    initializeDialogs();
    initializeSessionActivity();
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeUi, { once: true });
} else {
    initializeUi();
}
