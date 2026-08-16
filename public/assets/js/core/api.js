/**
 * Thin fetch wrapper. Attaches the CSRF token to every mutating request and
 * normalises errors into a single shape the UI can always render.
 */

const csrfToken = () =>
    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

/**
 * The install's base path, e.g. '' at the domain root or '/RoyalSpin/public'
 * in a subfolder. Every URL the frontend builds goes through `url()` so the
 * same code works in both.
 */
export const basePath =
    document.querySelector('meta[name="base-path"]')?.getAttribute('content') ?? '';

/** '/api/rooms' -> '/RoyalSpin/public/api/rooms' */
export function url(path = '/') {
    const clean = `/${String(path).replace(/^\/+/, '')}`;
    return `${basePath}${clean}` || '/';
}

/** Navigate, honouring the base path. Server-sent redirects already include it. */
export function goTo(path) {
    window.location.href = path.startsWith(basePath) && basePath !== '' ? path : url(path);
}

export class ApiError extends Error {
    constructor(message, status, field) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.field = field;
    }
}

async function request(method, path, body) {
    // Callers pass app-absolute paths ('/api/rooms'); the base path is added here.
    const target = url(path);
    const options = {
        method,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    };

    if (body !== undefined) {
        options.headers['Content-Type'] = 'application/json';
        options.headers['X-CSRF-Token'] = csrfToken();
        options.body = JSON.stringify(body);
    }

    let response;
    try {
        response = await fetch(target, options);
    } catch {
        throw new ApiError('No connection to the server.', 0);
    }

    let payload = null;
    try {
        payload = await response.json();
    } catch {
        payload = null;
    }

    if (!response.ok || payload?.ok === false) {
        throw new ApiError(
            payload?.error ?? 'Something went wrong. Please try again.',
            response.status,
            payload?.field,
        );
    }

    return payload ?? {};
}

export const api = {
    get: (url) => request('GET', url),
    post: (url, body = {}) => request('POST', url, body),
};

/** Client-generated ids make every action safe to retry exactly once. */
export function actionId() {
    if (globalThis.crypto?.randomUUID) {
        return globalThis.crypto.randomUUID().replace(/-/g, '').slice(0, 32);
    }
    return `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 10)}`;
}
