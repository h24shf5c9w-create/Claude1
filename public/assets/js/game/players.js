/**
 * Player cards + owned-upgrade chips.
 */

import { escapeHtml } from '../core/ui.js';

const RARITY_CLASS = {
    rare: 'build-dot--rare',
    epic: 'build-dot--epic',
    legendary: 'build-dot--legendary',
};

export class PlayerList {
    constructor(root) {
        this.root = root;
    }

    /** @param {Array<object>} players @param {number} targetCoins */
    render(players, targetCoins) {
        if (!this.root) {
            return;
        }

        this.root.innerHTML = players.map((player) => {
            const online = player.connection_status === 'online';
            const spins = player.is_current && player.spins_remaining > 0
                ? `<span>🎰 ${player.spins_remaining} spins left</span>`
                : '';
            const dice = player.is_current && player.dice_value
                ? `<span>🎲 ${player.dice_value}</span>`
                : '';

            const progress = targetCoins > 0
                ? Math.min(100, Math.round((player.coins / targetCoins) * 100))
                : 0;

            const build = (player.upgrades ?? [])
                .slice(0, 12)
                .map((upgrade) =>
                    `<span class="build-dot ${RARITY_CLASS[upgrade.rarity] ?? ''}"></span>`)
                .join('');

            return `
            <div class="player-row ${player.is_current ? 'player-row--active' : ''}">
                <span class="player-row__seat">${player.is_current ? '👑' : escapeHtml(String(player.seat + 1))}</span>
                <span class="player-row__body">
                    <span class="player-row__name">
                        ${escapeHtml(player.username)}${player.is_you ? ' <span class="pill">You</span>' : ''}
                    </span>
                    <span class="player-row__meta">
                        <span class="status status--${online ? 'online' : 'offline'}">
                            <span class="status__dot"></span>${online ? 'Online' : 'Connection lost'}
                        </span>
                        ${dice}${spins}
                        <span>${progress}%</span>
                        <span class="build-dots" title="${(player.upgrades ?? []).length} upgrades">${build}</span>
                    </span>
                </span>
                <span class="player-row__coins">${Number(player.coins).toLocaleString('en-US')}</span>
            </div>`;
        }).join('');
    }
}

/** Chips showing what the local player currently owns. */
export function renderOwnedUpgrades(root, upgrades) {
    if (!root) {
        return;
    }
    if (!upgrades || upgrades.length === 0) {
        root.innerHTML = '<span class="muted" style="font-size:.84rem">No upgrades yet.</span>';
        return;
    }

    root.innerHTML = upgrades.map((upgrade) => `
        <span class="owned-chip owned-chip--${escapeHtml(upgrade.rarity)}" title="${escapeHtml(upgrade.description)}">
            <svg viewBox="0 0 64 64" aria-hidden="true"><use href="#ico-${escapeHtml(upgrade.icon)}"></use></svg>
            ${escapeHtml(upgrade.name)}${upgrade.max_level > 1 ? ` <span class="muted">L${upgrade.level}</span>` : ''}
        </span>`).join('');
}
