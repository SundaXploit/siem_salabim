import { animate } from 'animejs/animation';

const reduceMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

function run(targets, options) {
    if (reduceMotion() || !targets || (targets.length !== undefined && targets.length === 0)) return;
    animate(targets, options);
}

export function initializeMotion() {
    const page = document.querySelector('[data-ui-page]');
    if (page) {
        run(page, {
            opacity: [0, 1],
            y: [5, 0],
            duration: 220,
            ease: 'outQuad',
        });
    }

    document.addEventListener('siem:themechange', (event) => {
        const controls = document.querySelectorAll('[data-theme-toggle] svg');
        run(controls, {
            rotate: event.detail.theme === 'dark' ? [0, -12, 0] : [0, 12, 0],
            duration: 180,
            ease: 'outQuad',
        });
    });

    const observedOverlays = new WeakSet();
    const observeOverlay = (overlay) => {
        if (observedOverlays.has(overlay)) return;
        observedOverlays.add(overlay);
        observer.observe(overlay, { attributes: true, attributeFilter: ['style'] });
    };

    const observer = new MutationObserver((records) => {
        records.forEach((record) => {
            if (record.type !== 'attributes' || record.attributeName !== 'style') return;
            const overlay = record.target;
            if (!overlay.classList.contains('modal-backdrop') || overlay.style.display === 'none') return;
            const dialog = overlay.querySelector('.modal-box');
            run(dialog, { opacity: [0, 1], y: [8, 0], duration: 180, ease: 'outQuad' });
        });
    });

    document.querySelectorAll('.modal-backdrop').forEach((overlay) => {
        observeOverlay(overlay);
    });

    const pageObserver = new MutationObserver((records) => {
        records.forEach((record) => {
            record.addedNodes.forEach((node) => {
                if (!(node instanceof Element)) return;
                if (node.matches('.modal-backdrop')) observeOverlay(node);
                node.querySelectorAll?.('.modal-backdrop').forEach(observeOverlay);
            });
        });
    });

    pageObserver.observe(document.body, { childList: true, subtree: true });
}
