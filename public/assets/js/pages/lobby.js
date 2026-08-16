/**
 * Lobby: live seat list, ready toggle, host start.
 *
 * A lobby has no match id yet, so it cannot use the game event outbox. Instead
 * the realtime service watches subscribed rooms and pushes a `lobby_state`
 * frame whenever the rendered state actually changes; if the socket is not
 * available this falls back to a light poll of the same endpoint.
 */

import { api, url } from '../core/api.js';
import { escapeHtml, toast } from '../core/ui.js';
import { sound } from '../core/sound.js';

export function initLobby(root) {
    const config = JSON.parse(root.getAttribute('data-lobby') ?? '{}');

    const seatList = root.querySelector('[data-seat-list]');
    const playerCount = root.querySelector('[data-player-count]');
    const startButton = root.querySelector('[data-start-match]');
    const startHint = root.querySelector('[data-start-hint]');
    const readyButton = root.querySelector('[data-ready-toggle]');
    const leaveButton = root.querySelector('[data-leave-room]');
    const copyButton = root.querySelector('[data-copy-code]');

    let socket = null;
    let pollTimer = null;
    let ready = false;
    let starting = false;

    /* --------------------------------------------------------- rendering */

    function render(room) {
        if (!room || room.closed) {
            toast('This room was closed.', 'error');
            setTimeout(() => { window.location.href = url('/dashboard'); }, 1200);
            return;
        }

        if (room.status === 'active' && room.match_id) {
            window.location.href = url(`/game/${room.match_id}`);
            return;
        }

        const players = room.players ?? [];
        if (playerCount) {
            playerCount.textContent = `${players.length}/${room.max_players}`;
        }

        if (seatList) {
            const seats = players.map((player) => `
                <li class="seat">
                    <span class="seat__avatar">${escapeHtml(player.username.charAt(0).toUpperCase())}</span>
                    <span class="seat__name">${escapeHtml(player.username)}</span>
                    <span class="seat__tags">
                        ${player.is_host ? '<span class="pill">Host</span>' : ''}
                        <span class="pill">${player.is_ready ? 'Ready' : 'Waiting'}</span>
                    </span>
                </li>`);

            for (let i = players.length; i < room.max_players; i += 1) {
                seats.push(`
                    <li class="seat seat--empty">
                        <span class="seat__avatar">?</span>
                        <span class="seat__name muted">Waiting for a player…</span>
                    </li>`);
            }
            seatList.innerHTML = seats.join('');
        }

        // Keep the local ready flag in sync with the server's view.
        const me = players.find((player) => player.user_id === config.userId);
        if (me && readyButton) {
            ready = Boolean(me.is_ready);
            readyButton.textContent = ready ? "I'm not ready" : "I'm ready";
            readyButton.setAttribute('aria-pressed', String(ready));
            readyButton.classList.toggle('button--primary', !ready);
            readyButton.classList.toggle('button--ghost', ready);
        }

        if (startButton) {
            const enough = players.length >= (room.min_players ?? 2);
            const allReady = players.every((player) => player.is_ready);
            startButton.disabled = starting || !enough || !allReady;

            if (startHint) {
                startHint.textContent = !enough
                    ? `Waiting for at least ${room.min_players} players…`
                    : (!allReady ? 'Waiting for everyone to be ready…' : 'Ready to go!');
            }
        }
    }

    /* --------------------------------------------------------- transport */

    async function poll() {
        try {
            const result = await api.get(`/api/rooms/${config.roomId}`);
            if (result.started && result.match_id) {
                window.location.href = url(`/game/${result.match_id}`);
                return;
            }
            render(result.room);
        } catch (error) {
            if (error.status === 404) {
                toast('This room was closed.', 'error');
                setTimeout(() => { window.location.href = url('/dashboard'); }, 1200);
                clearInterval(pollTimer);
            }
        }
    }

    /**
     * Optional upgrade: if a realtime service is available the lobby is pushed
     * the moment anything changes. If not (shared hosting), the poll below is
     * the only mechanism and everything still works.
     */
    async function connectSocket() {
        if (!('WebSocket' in window) || !config.wsEnabled) {
            return;
        }
        let ticket;
        try {
            ({ ticket } = await api.get('/api/ws-ticket'));
        } catch {
            return;
        }

        let endpoint = (config.wsUrl ?? '').trim();
        if (!endpoint) {
            const scheme = location.protocol === 'https:' ? 'wss:' : 'ws:';
            endpoint = `${scheme}//${location.hostname}:${config.wsPort || 8081}`;
        }

        try {
            socket = new WebSocket(endpoint);
        } catch {
            return;
        }

        socket.addEventListener('open', () =>
            socket.send(JSON.stringify({ type: 'auth', ticket })));

        socket.addEventListener('message', (message) => {
            let frame;
            try {
                frame = JSON.parse(message.data);
            } catch {
                return;
            }
            if (frame.type === 'authenticated') {
                socket.send(JSON.stringify({ type: 'lobby_subscribe', room_id: config.roomId }));
            } else if (frame.type === 'lobby_state') {
                render(frame.room);
            }
        });

        socket.addEventListener('close', () => { socket = null; });
    }

    /* ----------------------------------------------------------- actions */

    readyButton?.addEventListener('click', async () => {
        readyButton.disabled = true;
        sound.button();
        try {
            const result = await api.post(`/api/rooms/${config.roomId}/ready`, { ready: !ready });
            render(result.room);
        } catch (error) {
            toast(error.message, 'error');
        }
        readyButton.disabled = false;
    });

    startButton?.addEventListener('click', async () => {
        if (starting) {
            return;
        }
        starting = true;
        startButton.disabled = true;
        sound.button();

        try {
            const result = await api.post(`/api/rooms/${config.roomId}/start`);
            window.location.href = result.redirect;
        } catch (error) {
            toast(error.message, 'error');
            starting = false;
            startButton.disabled = false;
        }
    });

    leaveButton?.addEventListener('click', async () => {
        leaveButton.disabled = true;
        try {
            const result = await api.post(`/api/rooms/${config.roomId}/leave`);
            window.location.href = result.redirect;
        } catch (error) {
            toast(error.message, 'error');
            leaveButton.disabled = false;
        }
    });

    copyButton?.addEventListener('click', async () => {
        const code = copyButton.getAttribute('data-code') ?? '';
        try {
            await navigator.clipboard.writeText(code);
            toast('Room code copied.', 'success');
        } catch {
            // Clipboard API needs a secure context; fall back to a selection.
            const range = document.createRange();
            const target = root.querySelector('[data-room-code]');
            if (target) {
                range.selectNodeContents(target);
                const selection = window.getSelection();
                selection?.removeAllRanges();
                selection?.addRange(range);
                toast('Select and copy the highlighted code.', 'info');
            }
        }
    });

    /* ------------------------------------------------------------- start */

    connectSocket();
    // The poll is a safety net; it is cheap and stops as soon as we navigate.
    pollTimer = setInterval(poll, 2500);
    poll();

    window.addEventListener('beforeunload', () => {
        clearInterval(pollTimer);
        socket?.close();
    });
}
