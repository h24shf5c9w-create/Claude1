/**
 * Realtime transport.
 *
 * Primary path is a WebSocket to the `bin/ws-server.php` service. If that
 * cannot be established (blocked port, corporate proxy, hostile mobile
 * network) it degrades to HTTP polling of the *same* event stream — the server
 * writes every event to one outbox, so both paths see an identical, ordered
 * sequence and the game plays the same either way.
 *
 * Events are keyed by a per-match `seq`, so nothing is ever applied twice —
 * which is what makes reconnects and refreshes safe.
 */

import { api, ApiError } from './api.js';

const WS_RETRY_LIMIT = 4;
const POLL_INTERVAL = 1200;

export class Transport {
    /**
     * @param {{matchId:number, wsUrl:string, wsPort:number, heartbeat:number,
     *          onState:Function, onEvent:Function, onStatus:Function}} options
     */
    constructor(options) {
        this.options = options;
        this.matchId = options.matchId;
        this.socket = null;
        this.mode = 'connecting';       // connecting | websocket | polling | closed
        this.lastSeq = 0;
        this.attempts = 0;
        this.pending = new Map();
        this.nextRequestId = 1;
        this.timers = { heartbeat: null, poll: null, retry: null };
        this.closed = false;
        this.polling = false;
    }

    /* ------------------------------------------------------------ lifecycle */

    async start() {
        // Always seed from the authoritative snapshot first, so the UI is
        // correct even before any transport is up.
        await this.refreshState();
        this.connectSocket();

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible' && !this.closed) {
                this.resync();
            }
        });
        window.addEventListener('online', () => this.resync());
    }

    stop() {
        this.closed = true;
        this.clearTimers();
        try {
            this.socket?.close();
        } catch { /* already gone */ }
        this.socket = null;
        this.mode = 'closed';
    }

    clearTimers() {
        Object.values(this.timers).forEach((timer) => timer && clearInterval(timer));
        Object.values(this.timers).forEach((timer) => timer && clearTimeout(timer));
        this.timers = { heartbeat: null, poll: null, retry: null };
    }

    setMode(mode) {
        if (this.mode === mode) {
            return;
        }
        this.mode = mode;
        this.options.onStatus?.(mode);
    }

    /* ----------------------------------------------------------- websocket */

    resolveSocketUrl(ticket) {
        let base = (this.options.wsUrl || '').trim();
        if (!base) {
            const scheme = location.protocol === 'https:' ? 'wss:' : 'ws:';
            base = `${scheme}//${location.hostname}:${this.options.wsPort || 8081}`;
        }
        const separator = base.includes('?') ? '&' : '?';
        return `${base}${separator}t=${encodeURIComponent(ticket)}`;
    }

    async connectSocket() {
        if (this.closed || !('WebSocket' in window)) {
            this.startPolling();
            return;
        }

        let ticket;
        try {
            ({ ticket } = await api.get('/api/ws-ticket'));
        } catch {
            this.scheduleRetry();
            return;
        }

        let socket;
        try {
            socket = new WebSocket(this.resolveSocketUrl(ticket));
        } catch {
            this.scheduleRetry();
            return;
        }
        this.socket = socket;

        socket.addEventListener('open', () => {
            this.attempts = 0;
            // The ticket travels in the first frame as well as the query
            // string; the server only trusts the frame.
            socket.send(JSON.stringify({ type: 'auth', ticket }));
        });

        socket.addEventListener('message', (message) => this.handleSocketMessage(message));

        socket.addEventListener('close', () => {
            if (this.closed) {
                return;
            }
            this.socket = null;
            this.setMode('connecting');
            this.scheduleRetry();
        });

        socket.addEventListener('error', () => {
            try {
                socket.close();
            } catch { /* noop */ }
        });
    }

    handleSocketMessage(message) {
        let frame;
        try {
            frame = JSON.parse(message.data);
        } catch {
            return;
        }

        switch (frame.type) {
            case 'authenticated':
                this.socket?.send(JSON.stringify({ type: 'subscribe', match_id: this.matchId }));
                break;

            case 'state':
                this.stopPolling();
                this.setMode('websocket');
                this.applyState(frame.state);
                this.startHeartbeat();
                break;

            case 'event':
                this.applyEvent(frame);
                break;

            case 'action_result': {
                const resolver = this.pending.get(frame.request_id);
                if (resolver) {
                    this.pending.delete(frame.request_id);
                    resolver(frame.result ?? { ok: false, error: 'Empty response.' });
                }
                break;
            }

            case 'auth_error':
                // A stale ticket is normal after a long sleep; retry once with
                // a fresh one before giving up on the socket.
                this.scheduleRetry();
                break;

            case 'error':
                this.options.onStatus?.('error', frame.error);
                break;

            default:
                break;
        }
    }

    scheduleRetry() {
        if (this.closed || this.timers.retry) {
            return;
        }
        this.attempts += 1;

        if (this.attempts > WS_RETRY_LIMIT) {
            // Give up on WebSockets for this session and poll instead — the
            // game keeps working, just with ~1s more latency.
            this.startPolling();
            return;
        }

        const delay = Math.min(8000, 600 * 2 ** (this.attempts - 1));
        this.startPolling();                 // stay playable while we retry
        this.timers.retry = setTimeout(() => {
            this.timers.retry = null;
            this.connectSocket();
        }, delay);
    }

    startHeartbeat() {
        if (this.timers.heartbeat) {
            clearInterval(this.timers.heartbeat);
        }
        const interval = Math.max(5, this.options.heartbeat || 15) * 1000;
        this.timers.heartbeat = setInterval(() => {
            if (this.socket?.readyState === WebSocket.OPEN) {
                this.socket.send(JSON.stringify({ type: 'ping' }));
            }
        }, interval);
    }

    /* ------------------------------------------------------------- polling */

    startPolling() {
        if (this.polling || this.closed) {
            return;
        }
        this.polling = true;
        this.setMode('polling');

        this.timers.poll = setInterval(() => this.pollEvents(), POLL_INTERVAL);
        this.timers.heartbeat = setInterval(() => {
            api.post(`/api/match/${this.matchId}/heartbeat`).catch(() => {});
        }, Math.max(5, this.options.heartbeat || 15) * 1000);

        this.pollEvents();
    }

    stopPolling() {
        if (!this.polling) {
            return;
        }
        this.polling = false;
        if (this.timers.poll) {
            clearInterval(this.timers.poll);
            this.timers.poll = null;
        }
    }

    async pollEvents() {
        if (this.closed) {
            return;
        }
        try {
            const { events } = await api.get(
                `/api/match/${this.matchId}/events?since=${this.lastSeq}`,
            );
            (events ?? []).forEach((event) => this.applyEvent(event));
        } catch {
            /* transient — the next tick retries */
        }
    }

    /* --------------------------------------------------------- application */

    applyState(state) {
        if (!state) {
            return;
        }
        this.lastSeq = Number(state.match?.seq ?? 0);
        this.options.onState?.(state);
    }

    applyEvent(event) {
        const seq = Number(event.seq ?? 0);
        if (seq <= this.lastSeq) {
            return;   // already applied (duplicate delivery, refresh, replay)
        }
        // A gap means we missed something; rebuild from the server instead of
        // guessing. This is the rule that keeps clients honest after a drop.
        if (seq > this.lastSeq + 1) {
            this.lastSeq = seq;
            this.options.onEvent?.(event.event ?? event.type, event.payload ?? {}, seq);
            this.refreshState();
            return;
        }

        this.lastSeq = seq;
        this.options.onEvent?.(event.event ?? event.type, event.payload ?? {}, seq);
    }

    async refreshState() {
        try {
            const { state } = await api.get(`/api/match/${this.matchId}/state`);
            this.applyState(state);
            return state;
        } catch (error) {
            if (error instanceof ApiError && error.status === 404) {
                this.options.onStatus?.('gone');
            }
            return null;
        }
    }

    /** Reconnect handling: never resume from local state, always re-read. */
    resync() {
        if (this.closed) {
            return;
        }
        if (this.socket?.readyState === WebSocket.OPEN) {
            this.socket.send(JSON.stringify({ type: 'request_state' }));
        } else {
            this.refreshState();
        }
    }

    /* ------------------------------------------------------------- actions */

    /**
     * Send an action. Resolves with the server's result object either way —
     * `{ok:true, ...}` or `{ok:false, error:'…'}`.
     */
    async send(action, payload = {}) {
        if (this.socket?.readyState === WebSocket.OPEN && this.mode === 'websocket') {
            const requestId = String(this.nextRequestId++);
            return new Promise((resolve) => {
                this.pending.set(requestId, resolve);
                this.socket.send(JSON.stringify({ type: action, request_id: requestId, ...payload }));

                // Don't leave the UI locked if the socket dies mid-action;
                // fall back to HTTP for this one request.
                setTimeout(async () => {
                    if (!this.pending.has(requestId)) {
                        return;
                    }
                    this.pending.delete(requestId);
                    resolve(await this.sendOverHttp(action, payload));
                }, 6000);
            });
        }

        return this.sendOverHttp(action, payload);
    }

    async sendOverHttp(action, payload) {
        const endpoints = {
            roll_dice: 'roll',
            reroll_dice: 'reroll',
            spin: 'spin',
            buy_upgrade: 'buy',
            end_turn: 'end-turn',
        };
        const endpoint = endpoints[action];
        if (!endpoint) {
            return { ok: false, error: 'Unknown action.' };
        }

        try {
            const result = await api.post(`/api/match/${this.matchId}/${endpoint}`, payload);
            // Pull the resulting events straight away rather than waiting for
            // the next poll tick — keeps the acting player's UI snappy.
            this.pollEvents();
            return result;
        } catch (error) {
            return { ok: false, error: error.message };
        }
    }
}
