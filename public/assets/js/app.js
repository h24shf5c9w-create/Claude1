/**
 * Entry point. Loads only the module the current page needs.
 */

import { initSoundControls } from './core/sound.js';

document.documentElement.classList.remove('no-js');

initSoundControls();

const page = document.querySelector('[data-page]');
const name = page?.getAttribute('data-page');

(async () => {
    try {
        switch (name) {
            case 'dashboard': {
                const { initDashboard } = await import('./pages/dashboard.js');
                initDashboard(page);
                break;
            }
            case 'lobby': {
                const { initLobby } = await import('./pages/lobby.js');
                initLobby(page);
                break;
            }
            case 'game': {
                const { initGame } = await import('./game/index.js');
                initGame(page);
                break;
            }
            default:
                break;
        }
    } catch (error) {
        console.error('[royal-spin] failed to start page module', error);
    }
})();
