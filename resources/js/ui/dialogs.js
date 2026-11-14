// Focus handling for inline Alpine dialogs; the shared Blade modal manages its own focus.
export function initializeDialogs() {
    const opened = new Map();
    const visible = modal => modal.getClientRects().length > 0;
    const focusables = modal => [...modal.querySelectorAll('a[href],button,input,textarea,select,[tabindex]')]
        .filter(element => !element.disabled && element.tabIndex >= 0 && element.getClientRects().length);
    const sync = () => {
        for (const modal of document.querySelectorAll('.modal-backdrop[role="dialog"]')) {
            if (visible(modal) && !opened.has(modal)) {
                opened.set(modal, document.activeElement);
                (focusables(modal)[0] || modal).focus();
            }
        }
        for (const [modal, previous] of opened) {
            if (!modal.isConnected || !visible(modal)) {
                opened.delete(modal);
                if (previous?.isConnected) previous.focus();
            }
        }
    };
    const observer = new MutationObserver(sync);
    observer.observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['style'] });
    const trap = event => {
        if (event.key !== 'Tab') return;
        const modal = [...opened.keys()].findLast(visible);
        if (!modal) return;
        const elements = focusables(modal);
        if (!elements.length) { event.preventDefault(); return; }
        const first = elements[0], last = elements.at(-1);
        if (event.shiftKey && (document.activeElement === first || !modal.contains(document.activeElement))) {
            event.preventDefault(); last.focus();
        } else if (!event.shiftKey && (document.activeElement === last || !modal.contains(document.activeElement))) {
            event.preventDefault(); first.focus();
        }
    };
    document.addEventListener('keydown', trap);
    window.addEventListener('pagehide', () => {
        observer.disconnect();
        document.removeEventListener('keydown', trap);
        opened.clear();
    }, { once: true });
}
