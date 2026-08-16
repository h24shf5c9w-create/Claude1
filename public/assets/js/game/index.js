/**
 * Game screen controller.
 *
 * Owns the flow: render authoritative state -> take an action -> animate the
 * event that comes back. It never computes a coin value, a symbol, a price or
 * a turn — those all arrive from the server.
 */

import { actionId, url } from '../core/api.js';
import { Transport } from '../core/transport.js';
import { animateNumber, toast, vibrate } from '../core/ui.js';
import { sound } from '../core/sound.js';
import { ReelRenderer } from './reels.js';
import { Shop } from './shop.js';
import { PlayerList, renderOwnedUpgrades } from './players.js';

export function initGame(root) {
    const config = JSON.parse(root.getAttribute('data-game') ?? '{}');

    const el = {
        banner: root.querySelector('[data-turn-banner]'),
        coins: root.querySelector('[data-coins]'),
        targetFill: root.querySelector('[data-target-fill]'),
        targetBar: root.querySelector('[data-target-bar]'),
        round: root.querySelector('[data-round]'),
        die: root.querySelector('[data-die]'),
        spinsRemaining: root.querySelector('[data-spins-remaining]'),
        diceMods: root.querySelector('[data-dice-mods]'),
        machine: root.querySelector('[data-machine]'),
        payout: root.querySelector('[data-payout]'),
        payoutAmount: root.querySelector('[data-payout-amount]'),
        payoutBreakdown: root.querySelector('[data-payout-breakdown]'),
        rollButton: root.querySelector('[data-action="roll"]'),
        spinButton: root.querySelector('[data-action="spin"]'),
        rerollButton: root.querySelector('[data-action="reroll"]'),
        endTurnButton: root.querySelector('[data-action="end-turn"]'),
        shopActions: root.querySelector('[data-shop-actions]'),
        ownedList: root.querySelector('[data-owned-list]'),
        connectionBanner: document.querySelector('[data-connection-banner]'),
        bigwin: document.querySelector('[data-bigwin]'),
    };

    const reels = new ReelRenderer(el.machine, config.symbols ?? []);
    const players = new PlayerList(root.querySelector('[data-player-list]'));

    /** Everything the UI knows. Replaced wholesale by server snapshots. */
    let state = config.state;
    let busy = false;               // an action is in flight or animating
    let animationQueue = Promise.resolve();

    /* ================================================================== */
    /*  Transport                                                          */
    /* ================================================================== */

    const transport = new Transport({
        matchId: config.matchId,
        wsEnabled: config.wsEnabled,
        wsUrl: config.wsUrl,
        wsPort: config.wsPort,
        heartbeat: config.heartbeat,
        onState: (next) => {
            state = next;
            render();
        },
        onEvent: (type, payload) => enqueue(() => handleEvent(type, payload)),
        onStatus: (mode, message) => {
            if (mode === 'error' && message) {
                toast(message, 'error');
                return;
            }
            if (mode === 'gone') {
                toast('This match is no longer available.', 'error');
                setTimeout(() => { window.location.href = url('/dashboard'); }, 1500);
                return;
            }
            const degraded = mode === 'connecting';
            el.connectionBanner?.toggleAttribute('hidden', !degraded);
            if (mode === 'polling') {
                el.connectionBanner?.setAttribute('hidden', '');
            }
        },
    });

    /** Serialise animations so two events can never overlap on screen. */
    function enqueue(task) {
        animationQueue = animationQueue.then(task).catch((error) => {
            console.error('[royal-spin]', error);
        });
        return animationQueue;
    }

    /* ================================================================== */
    /*  Rendering                                                          */
    /* ================================================================== */

    function render() {
        if (!state) {
            return;
        }
        const { match, you } = state;

        // --- header ------------------------------------------------------
        if (el.round) {
            el.round.textContent = String(match.round_number);
        }

        // --- coins + progress --------------------------------------------
        const current = Number(el.coins?.textContent?.replace(/[^\d-]/g, '') ?? 0);
        animateNumber(el.coins, current, you.coins);
        const progress = Math.min(100, (you.coins / match.target_coins) * 100);
        if (el.targetFill) {
            el.targetFill.style.width = `${progress}%`;
        }
        el.targetBar?.setAttribute('aria-valuenow', String(you.coins));

        // --- turn banner -------------------------------------------------
        const activePlayer = state.players.find((player) => player.is_current);
        if (el.banner) {
            el.banner.classList.toggle('turn-banner--yours', Boolean(you.is_your_turn));
            el.banner.classList.toggle('turn-banner--finished', match.status === 'finished');

            if (match.status === 'finished') {
                el.banner.textContent = 'Match finished';
            } else if (you.is_your_turn) {
                el.banner.textContent = you.can_roll ? 'Your turn — roll the dice'
                    : you.can_spin ? 'Your turn — spin!'
                        : 'Your turn — shop or end turn';
            } else {
                el.banner.textContent = activePlayer
                    ? `${activePlayer.username}'s turn`
                    : 'Waiting…';
            }
        }

        // --- dice --------------------------------------------------------
        if (el.die) {
            el.die.textContent = you.dice_value ? String(you.dice_value) : '–';
            el.die.classList.toggle('die--upgraded', hasDiceUpgrade(you.upgrades));
        }
        if (el.spinsRemaining) {
            el.spinsRemaining.textContent = String(you.spins_remaining);
        }
        if (el.diceMods) {
            const parts = (you.dice_display ?? '').split(' · ');
            el.diceMods.textContent = parts.length > 1 ? parts[1] : '';
        }

        // --- actions -----------------------------------------------------
        toggle(el.rollButton, Boolean(you.can_roll));
        toggle(el.spinButton, Boolean(you.can_spin));
        toggle(el.rerollButton, Boolean(you.can_reroll));
        toggle(el.shopActions, Boolean(you.can_shop || you.can_end_turn));
        setDisabled(busy);

        // --- players + build ---------------------------------------------
        players.render(state.players, match.target_coins);
        renderOwnedUpgrades(el.ownedList, you.upgrades);
        reels.applyBuildStyling(you.upgrades ?? []);
        shop.update(you.shop_offers ?? [], you.coins, Boolean(you.can_shop));

        // --- reels: restore the last visible result on load/reconnect -----
        if (state.last_spin && !reels.busy) {
            reels.show(state.last_spin.reels);
        }

        if (match.status === 'finished') {
            setTimeout(() => { window.location.href = url(`/result/${match.id}`); }, 4200);
        }
    }

    function toggle(element, visible) {
        element?.toggleAttribute('hidden', !visible);
    }

    function setDisabled(disabled) {
        [el.rollButton, el.spinButton, el.rerollButton, el.endTurnButton].forEach((button) => {
            if (button) {
                button.disabled = disabled;
            }
        });
    }

    function hasDiceUpgrade(upgrades) {
        return (upgrades ?? []).some((upgrade) => upgrade.category === 'dice');
    }

    /* ================================================================== */
    /*  Event handling (everything the server tells us happened)           */
    /* ================================================================== */

    async function handleEvent(type, payload) {
        switch (type) {
            case 'dice_rolled':
                await animateDice(payload);
                break;

            case 'spin_result':
                await animateSpin(payload);
                break;

            case 'upgrade_purchased':
                announceUpgrade(payload);
                break;

            case 'turn_ended':
            case 'turn_skipped':
                sound.turn();
                if (type === 'turn_skipped') {
                    toast(`${payload.previous_username} was skipped (offline).`, 'info');
                }
                resetSpinDisplay();
                await transport.refreshState();
                break;

            case 'player_connection':
                if (payload.user_id !== config.userId) {
                    toast(
                        payload.status === 'online'
                            ? `${payload.username} reconnected.`
                            : `${payload.username} lost connection.`,
                        payload.status === 'online' ? 'success' : 'info',
                    );
                }
                await transport.refreshState();
                break;

            case 'shop_updated':
                if (state) {
                    state.you.shop_offers = payload.offers ?? [];
                    state.you.coins = payload.coins ?? state.you.coins;
                    shop.update(state.you.shop_offers, state.you.coins, Boolean(state.you.can_shop));
                }
                break;

            case 'match_finished':
                await celebrateVictory(payload);
                break;

            case 'match_started':
                await transport.refreshState();
                break;

            default:
                await transport.refreshState();
        }
    }

    async function animateDice(payload) {
        const dice = payload.dice ?? {};
        sound.dice();
        vibrate(24);

        if (el.die) {
            el.die.classList.add('is-rolling');
            // Tumble through faces while the die is in the air.
            const tumble = setInterval(() => {
                el.die.textContent = String(1 + Math.floor(Math.random() * 6));
            }, 90);

            await new Promise((resolve) => setTimeout(resolve, 760));
            clearInterval(tumble);
            el.die.classList.remove('is-rolling');
            el.die.textContent = String(dice.final_value ?? '–');
        }

        if (el.payoutAmount) {
            el.payoutAmount.className = 'payout__amount payout__amount--none';
            el.payoutAmount.textContent = `${dice.spins} spin${dice.spins === 1 ? '' : 's'}`;
        }
        if (el.payoutBreakdown) {
            el.payoutBreakdown.innerHTML = (dice.modifiers ?? [])
                .map((label) => `<span class="payout__chip">${label}</span>`).join('');
        }

        await transport.refreshState();
    }

    async function animateSpin(payload) {
        await reels.spin(payload.reels, { nearWin: Boolean(payload.near_win) });

        const total = payload.payout?.total ?? 0;
        const isTriple = payload.outcome === 'triple';

        // --- payout readout ---
        if (el.payoutAmount) {
            if (total > 0) {
                el.payoutAmount.className = 'payout__amount payout__amount--win';
                el.payoutAmount.textContent = `+${total.toLocaleString('en-US')} coins`;
            } else {
                el.payoutAmount.className = 'payout__amount payout__amount--none';
                el.payoutAmount.textContent = 'No win';
            }
        }
        if (el.payoutBreakdown) {
            el.payoutBreakdown.innerHTML = (payload.payout?.breakdown ?? [])
                .map((entry) => `<span class="payout__chip">${entry.label} ${entry.value}</span>`)
                .join('');
        }
        el.payout?.classList.remove('is-popping');
        void el.payout?.offsetWidth;
        el.payout?.classList.add('is-popping');

        // --- graded feedback by win size ---
        if (total <= 0) {
            sound.lose();
        } else if (!isTriple) {
            sound.pair();
            vibrate(14);
        } else if (payload.win_symbol === 'trophy') {
            sound.trophy();
            vibrate([30, 40, 90]);
            reels.shake();
            showBigWin(payload, 'ROYAL TROPHY');
        } else if (payload.win_symbol === 'crown') {
            sound.crown();
            vibrate([25, 35, 70]);
            reels.shake();
            showBigWin(payload, 'CROWN TRIPLE');
        } else if (payload.win_symbol === 'diamond') {
            sound.triple();
            vibrate([20, 30, 50]);
            reels.shake();
            showBigWin(payload, 'DIAMOND TRIPLE');
        } else {
            sound.triple();
            vibrate(22);
        }

        if (payload.bonus_spin) {
            toast('Second Chance — bonus spin!', 'success');
        }

        el.coins && animateNumber(el.coins, Number(el.coins.textContent.replace(/[^\d-]/g, '')), payload.coins);
        el.coins?.classList.remove('is-bumping');
        void el.coins?.offsetWidth;
        el.coins?.classList.add('is-bumping');

        await transport.refreshState();
    }

    function showBigWin(payload, title) {
        if (!el.bigwin) {
            return;
        }
        const symbol = payload.win_symbol;
        el.bigwin.querySelector('[data-bigwin-symbol]').innerHTML =
            `<svg class="sym-${symbol}" viewBox="0 0 64 64" aria-hidden="true"><use href="#sym-${symbol}"></use></svg>`;
        el.bigwin.querySelector('[data-bigwin-title]').textContent = title;
        el.bigwin.querySelector('[data-bigwin-amount]').textContent =
            `+${(payload.payout?.total ?? 0).toLocaleString('en-US')} COINS`;

        el.bigwin.classList.add('is-visible');
        setTimeout(() => el.bigwin.classList.remove('is-visible'), 1900);
    }

    function announceUpgrade(payload) {
        const mine = payload.user_id === config.userId;
        toast(
            mine
                ? `${payload.name.toUpperCase()} ACTIVATED`
                : `${payload.username} bought ${payload.name}`,
            mine ? 'success' : 'info',
        );
        if (mine && payload.reel) {
            reels.flash(Number(payload.reel));
        }
    }

    function resetSpinDisplay() {
        if (el.payoutAmount) {
            el.payoutAmount.className = 'payout__amount payout__amount--none';
            el.payoutAmount.textContent = 'Waiting for your turn';
        }
        if (el.payoutBreakdown) {
            el.payoutBreakdown.innerHTML = '';
        }
        if (el.die) {
            el.die.textContent = '–';
        }
    }

    async function celebrateVictory(payload) {
        sound.victory();
        vibrate([40, 60, 40, 60, 120]);
        if (el.bigwin) {
            el.bigwin.querySelector('[data-bigwin-symbol]').innerHTML =
                '<svg class="sym-trophy" viewBox="0 0 64 64" aria-hidden="true"><use href="#sym-trophy"></use></svg>';
            el.bigwin.querySelector('[data-bigwin-title]').textContent =
                `${(payload.winner_username ?? 'Someone').toUpperCase()} WINS`;
            el.bigwin.querySelector('[data-bigwin-amount]').textContent = '';
            el.bigwin.classList.add('is-visible');
        }
        setTimeout(() => { window.location.href = url(`/result/${config.matchId}`); }, 3600);
    }

    /* ================================================================== */
    /*  Actions                                                            */
    /* ================================================================== */

    /**
     * Every action is locked while in flight and carries a client-generated
     * action id, so a double tap (or a retry after a dropped socket) can never
     * produce two spins.
     */
    async function act(action, payload = {}) {
        if (busy) {
            return { ok: false };
        }
        busy = true;
        setDisabled(true);
        sound.button();

        const result = await transport.send(action, { action_id: actionId(), ...payload });

        if (!result.ok) {
            toast(result.error ?? 'That action was rejected.', 'error');
            await transport.refreshState();
        }

        busy = false;
        setDisabled(false);
        return result;
    }

    el.rollButton?.addEventListener('click', () => act('roll_dice'));
    el.rerollButton?.addEventListener('click', () => act('reroll_dice'));
    el.spinButton?.addEventListener('click', () => act('spin'));
    el.endTurnButton?.addEventListener('click', () => act('end_turn'));

    const shop = new Shop(document.querySelector('[data-shop-sheet]'), async (key) => {
        const result = await act('buy_upgrade', { upgrade_key: key });
        return Boolean(result.ok);
    });

    root.querySelectorAll('[data-open-shop]').forEach((button) =>
        button.addEventListener('click', () => {
            sound.button();
            shop.open();
        }));

    // Keyboard: space/enter on the game surface triggers the primary action.
    document.addEventListener('keydown', (event) => {
        if (event.code !== 'Space' || event.target.closest('input, button, a, [role="dialog"]')) {
            return;
        }
        event.preventDefault();
        if (state?.you?.can_spin) {
            act('spin');
        } else if (state?.you?.can_roll) {
            act('roll_dice');
        }
    });

    /* ------------------------------------------------- debug overlay ---- */
    const debugRefresh = root.querySelector('[data-debug-refresh]');
    debugRefresh?.addEventListener('click', async () => {
        const output = root.querySelector('[data-debug-output]');
        try {
            const response = await fetch(url(`/api/match/${config.matchId}/debug`), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await response.json();
            output.textContent = JSON.stringify(data.debug ?? data, null, 2);
        } catch {
            output.textContent = 'Debug endpoint unavailable (production mode).';
        }
    });

    render();
    transport.start();

    window.addEventListener('beforeunload', () => transport.stop());
}
