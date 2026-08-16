/**
 * Toasts, haptics and the reduced-motion helper.
 */

const stack = () => document.getElementById('toasts');

export function toast(message, kind = 'info', duration = 3200) {
    const host = stack();
    if (!host) {
        return;
    }

    const element = document.createElement('div');
    element.className = `toast toast--${kind}`;
    element.textContent = message;
    host.appendChild(element);

    setTimeout(() => {
        element.classList.add('is-leaving');
        setTimeout(() => element.remove(), 260);
    }, duration);
}

export const prefersReducedMotion = () =>
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/**
 * Progressive enhancement only — the game is fully playable without it, and it
 * is skipped entirely when the user asked for reduced motion.
 */
export function vibrate(pattern) {
    if (prefersReducedMotion() || !navigator.vibrate) {
        return;
    }
    try {
        navigator.vibrate(pattern);
    } catch {
        /* some browsers throw when the page is not visible */
    }
}

export const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[c]);
}

/** Count a number up over `duration` ms. Falls back to a direct set. */
export function animateNumber(element, from, to, duration = 500) {
    if (!element) {
        return;
    }
    if (prefersReducedMotion() || from === to) {
        element.textContent = to.toLocaleString('en-US');
        return;
    }

    const start = performance.now();
    const step = (now) => {
        const progress = Math.min(1, (now - start) / duration);
        const eased = 1 - (1 - progress) ** 3;
        element.textContent = Math.round(from + (to - from) * eased).toLocaleString('en-US');
        if (progress < 1) {
            requestAnimationFrame(step);
        }
    };
    requestAnimationFrame(step);
}
