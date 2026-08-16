/**
 * Thin fetch wrapper. Attaches the CSRF token to every mutating request and
 * normalises errors into a single shape the UI can always render.
 */

const csrfToken = () =>
    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

export class ApiError extends Error {
    constructor(message, status, field) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.field = field;
    }
}

async function request(method, url, body) {
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
        response = await fetch(url, options);
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
