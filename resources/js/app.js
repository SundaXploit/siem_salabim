import './bootstrap';

import Alpine from 'alpinejs';
import { alertTable } from './live-alerts';
import { refreshIcons } from './ui/icons';
import { initializeMotion } from './ui/motion';
import { applyTheme, initializeTheme, toggleTheme } from './ui/theme';

window.Alpine = Alpine;
window.alertTable = alertTable;
window.SIEMUI = { refreshIcons };
window.applyTheme = applyTheme;
window.toggleTheme = toggleTheme;

Alpine.start();

const initializeUi = () => {
    initializeTheme();
    refreshIcons();
    initializeMotion();
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeUi, { once: true });
} else {
    initializeUi();
}
