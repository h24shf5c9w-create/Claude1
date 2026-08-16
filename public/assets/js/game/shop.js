/**
 * Upgrade shop — bottom sheet on phones, centred dialog on tablets/desktop.
 *
 * Offers come from the server (persisted per player, so a refresh cannot
 * re-roll them and prices cannot be tampered with). This module only renders
 * them and reports the chosen key back.
 */

import { escapeHtml } from '../core/ui.js';
import { sound } from '../core/sound.js';

const RARITY_ORDER = { legendary: 0, epic: 1, rare: 2, common: 3 };

export class Shop {
    /**
     * @param {HTMLElement} sheet
     * @param {(key:string, card:HTMLElement)=>Promise<boolean>} onBuy
     */
    constructor(sheet, onBuy) {
        this.sheet = sheet;
        this.grid = sheet.querySelector('[data-shop-grid]');
        this.coinsLabel = sheet.querySelector('[data-shop-coins]');
        this.subtitle = sheet.querySelector('[data-shop-subtitle]');
        this.onBuy = onBuy;
        this.offers = [];
        this.coins = 0;
        this.enabled = false;
        this.lastFocused = null;

        sheet.querySelectorAll('[data-close-shop]').forEach((button) =>
            button.addEventListener('click', () => this.close()));

        sheet.addEventListener('click', (event) => {
            if (event.target === sheet) {
                this.close();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && this.isOpen()) {
                this.close();
            }
        });

        this.grid?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-buy]');
            if (button) {
                this.buy(button.getAttribute('data-buy'), button);
            }
        });
    }

    isOpen() {
        return this.sheet.classList.contains('is-open');
    }

    open() {
        this.lastFocused = document.activeElement;
        this.sheet.hidden = false;
        // next frame so the transition runs
        requestAnimationFrame(() => this.sheet.classList.add('is-open'));
        this.sheet.querySelector('[data-close-shop]')?.focus();
    }

    close() {
        this.sheet.classList.remove('is-open');
        setTimeout(() => {
            this.sheet.hidden = true;
        }, 260);
        if (this.lastFocused instanceof HTMLElement) {
            this.lastFocused.focus();
        }
    }

    /**
     * @param {Array<object>} offers
     * @param {number} coins
     * @param {boolean} enabled purchases are only legal in the shop phase
     */
    update(offers, coins, enabled) {
        this.offers = [...(offers ?? [])].sort(
            (a, b) => (RARITY_ORDER[a.rarity] ?? 9) - (RARITY_ORDER[b.rarity] ?? 9),
        );
        this.coins = coins;
        this.enabled = enabled;
        this.render();
    }

    render() {
        if (!this.grid) {
            return;
        }

        if (this.coinsLabel) {
            this.coinsLabel.textContent = `${this.coins.toLocaleString('en-US')} coins`;
        }
        if (this.subtitle) {
            this.subtitle.textContent = this.enabled
                ? 'Buy what you can afford, then end your turn.'
                : 'The shop opens after you have played your spins.';
        }

        if (this.offers.length === 0) {
            this.grid.innerHTML =
                '<p class="muted" style="font-size:.86rem">No upgrades on offer yet — play your spins first.</p>';
            return;
        }

        this.grid.innerHTML = this.offers.map((offer) => this.card(offer)).join('');
    }

    card(offer) {
        const affordable = this.coins >= offer.cost;
        const buyable = this.enabled && offer.available && affordable && !offer.purchased;

        const levelText = offer.max_level > 1
            ? `Level ${offer.current_level} → ${offer.next_level} of ${offer.max_level}`
            : (offer.current_level > 0 ? 'Owned' : 'New');

        let reason = '';
        if (offer.purchased) {
            reason = 'Bought';
        } else if (!offer.available) {
            reason = offer.reason ?? 'Unavailable';
        } else if (!affordable) {
            reason = 'Not enough coins';
        } else if (!this.enabled) {
            reason = 'Finish your spins';
        }

        return `
        <article class="upgrade-card upgrade-card--${escapeHtml(offer.rarity)} ${offer.purchased ? 'is-purchased' : ''}"
                 data-card="${escapeHtml(offer.key)}">
            <div class="upgrade-card__icon">
                <svg viewBox="0 0 64 64" aria-hidden="true"><use href="#ico-${escapeHtml(offer.icon)}"></use></svg>
            </div>
            <div>
                <div class="upgrade-card__head">
                    <span class="upgrade-card__name">${escapeHtml(offer.name)}</span>
                    <span class="upgrade-card__rarity">${escapeHtml(offer.rarity)}</span>
                </div>
                <div class="upgrade-card__tags">
                    <span class="pill">${escapeHtml(offer.category_label)}</span>
                    <span class="pill">${escapeHtml(levelText)}</span>
                </div>
                <p class="upgrade-card__desc">${escapeHtml(offer.description)}</p>
                <div class="upgrade-card__foot">
                    <span class="upgrade-card__level">${reason ? escapeHtml(reason) : ''}</span>
                    <span class="upgrade-card__price">
                        <span class="upgrade-card__cost">${Number(offer.cost).toLocaleString('en-US')}</span>
                        <button type="button" class="button button--primary button--small"
                                data-buy="${escapeHtml(offer.key)}" ${buyable ? '' : 'disabled'}>
                            Buy
                        </button>
                    </span>
                </div>
            </div>
        </article>`;
    }

    async buy(key, button) {
        if (!key || button.disabled) {
            return;
        }
        button.disabled = true;
        sound.button();

        const card = this.grid?.querySelector(`[data-card="${CSS.escape(key)}"]`);
        const ok = await this.onBuy(key, card);

        if (ok) {
            sound.upgrade();
            card?.classList.add('is-flying');
        } else {
            button.disabled = false;
        }
    }
}
