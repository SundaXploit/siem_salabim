import { animate } from 'animejs/animation';

const reduceMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

function run(targets, options) {
    if (reduceMotion() || !targets || (targets.length !== undefined && targets.length === 0)) return;
    return animate(targets, options);
}

export function initializeMotion() {
    const active = new Map();
    const themeChanged = (event) => {
        const controls = document.querySelectorAll('[data-theme-toggle] svg');
        run(controls, {
            rotate: event.detail.theme === 'dark' ? [0, -8, 0] : [0, 8, 0],
            duration: 140,
            ease: 'outQuad',
        });
    };
    document.addEventListener('siem:themechange', themeChanged);

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
            if (!overlay.classList.contains('modal-backdrop')) return;
            active.get(overlay)?.pause();
            active.delete(overlay);
            if (overlay.style.display === 'none') return;
            const dialog = overlay.querySelector('.modal-box');
            const animation = run(dialog, { opacity: [0.85, 1], y: [4, 0], duration: 220, ease: 'outQuad' });
            if (animation) active.set(overlay, animation);
        });
    });

    document.querySelectorAll('.modal-backdrop').forEach((overlay) => {
        observeOverlay(overlay);
    });

    const pageObserver = new MutationObserver((records) => {
        records.forEach((record) => {
            for (const [overlay, animation] of active) {
                if (!overlay.isConnected) { animation.pause(); active.delete(overlay); }
            }
            record.addedNodes.forEach((node) => {
                if (!(node instanceof Element)) return;
                if (node.matches('.modal-backdrop')) observeOverlay(node);
                node.querySelectorAll?.('.modal-backdrop').forEach(observeOverlay);
            });
        });
    });

    pageObserver.observe(document.body, { childList: true, subtree: true });

    window.addEventListener('pagehide', () => {
        observer.disconnect();
        pageObserver.disconnect();
        document.removeEventListener('siem:themechange', themeChanged);
        active.forEach(animation => animation.pause());
        active.clear();
    }, { once: true });
}
