/**
 * Dashboard: create a room, join by code.
 */

import { api } from '../core/api.js';
import { toast } from '../core/ui.js';
import { sound } from '../core/sound.js';

export function initDashboard(root) {
    /* ---------------------------------------------------- create a room */
    const createForm = root.querySelector('[data-create-room]');
    createForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = createForm.querySelector('[data-create-submit]');
        if (button.disabled) {
            return;
        }

        button.disabled = true;
        sound.button();

        const maxPlayers = Number(
            createForm.querySelector('input[name="max_players"]:checked')?.value ?? 4,
        );

        try {
            const result = await api.post('/api/rooms', { max_players: maxPlayers });
            window.location.href = result.redirect;
        } catch (error) {
            toast(error.message, 'error');
            button.disabled = false;
        }
    });

    /* ------------------------------------------------------ join a room */
    const joinForm = root.querySelector('[data-join-room]');
    const codeInput = root.querySelector('[data-code-input]');

    // Normalise as the user types: uppercase, strip anything not in the
    // room-code alphabet, and submit automatically on the fourth character.
    codeInput?.addEventListener('input', () => {
        const cleaned = codeInput.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 4);
        if (cleaned !== codeInput.value) {
            codeInput.value = cleaned;
        }
        if (cleaned.length === 4) {
            joinForm?.requestSubmit();
        }
    });

    joinForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = joinForm.querySelector('button[type="submit"]');
        const code = (codeInput?.value ?? '').trim();

        if (code.length !== 4) {
            toast('A room code is 4 characters.', 'error');
            return;
        }
        if (button.disabled) {
            return;
        }

        button.disabled = true;
        sound.button();

        try {
            const result = await api.post('/api/rooms/join', { code });
            window.location.href = result.redirect;
        } catch (error) {
            toast(error.message, 'error');
            button.disabled = false;
            codeInput?.select();
        }
    });

    // Deep link: /dashboard#create focuses the create form.
    if (window.location.hash === '#create') {
        createForm?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}
