/**
 * Slot renderer + animator.
 *
 * The server has already decided the three symbols before this file runs. All
 * this does is make the reels *look* like they landed there: build a strip of
 * random filler symbols with the real result at the end, translate the strip,
 * and stop the reels one after another.
 *
 * The animation can never change the outcome.
 */

import { prefersReducedMotion, sleep, vibrate } from '../core/ui.js';
import { sound } from '../core/sound.js';

const FILLER_COUNT = 12;
const BASE_DURATION = 620;
const STAGGER = 190;
const SUSPENSE_EXTRA = 620;

export class ReelRenderer {
    /** @param {HTMLElement} root @param {string[]} symbolKeys */
    constructor(root, symbolKeys) {
        this.root = root;
        this.symbols = symbolKeys;
        this.reels = [1, 2, 3].map((index) => root.querySelector(`[data-reel="${index}"]`));
        this.busy = false;
    }

    static cell(symbol) {
        return `<div class="reel__cell">
            <svg class="sym-${symbol}" viewBox="0 0 64 64" aria-hidden="true"><use href="#sym-${symbol}"></use></svg>
        </div>`;
    }

    randomSymbol() {
        return this.symbols[Math.floor(Math.random() * this.symbols.length)];
    }

    /** Paint a static result with no animation (used on load and reconnect). */
    show(result) {
        result.forEach((symbol, index) => {
            const strip = this.reels[index]?.querySelector('[data-strip]');
            if (strip) {
                strip.style.transform = '';
                strip.innerHTML = ReelRenderer.cell(symbol);
            }
        });
    }

    /**
     * Animate to the given result.
     *
     * @param {string[]} result three symbol keys
     * @param {{nearWin?:boolean}} options
     */
    async spin(result, { nearWin = false } = {}) {
        if (this.busy) {
            return;
        }
        this.busy = true;

        if (prefersReducedMotion()) {
            this.show(result);
            sound.reelStop();
            this.busy = false;
            return;
        }

        // Build each strip: filler symbols then the real one at the bottom.
        this.reels.forEach((reel, index) => {
            const strip = reel?.querySelector('[data-strip]');
            if (!strip) {
                return;
            }
            const cells = [];
            for (let i = 0; i < FILLER_COUNT; i += 1) {
                cells.push(ReelRenderer.cell(this.randomSymbol()));
            }
            cells.push(ReelRenderer.cell(result[index]));

            strip.innerHTML = cells.join('');
            strip.style.transform = 'translate3d(0,0,0)';
            strip.style.transition = 'none';
            reel.classList.add('is-blurred');
        });

        // Force a reflow so the transition below actually runs.
        void this.root.offsetHeight;

        sound.reelSpin();

        let longest = 0;
        this.reels.forEach((reel, index) => {
            const strip = reel?.querySelector('[data-strip]');
            if (!strip) {
                return;
            }

            // Reel 3 gets a longer, dramatic stop when reels 1+2 already match.
            const isSuspenseReel = nearWin && index === 2;
            const duration = BASE_DURATION + index * STAGGER + (isSuspenseReel ? SUSPENSE_EXTRA : 0);
            longest = Math.max(longest, duration);

            strip.style.transition = `transform ${duration}ms cubic-bezier(.16,.78,.28,1)`;
            strip.style.transform = `translate3d(0, -${FILLER_COUNT * 100}%, 0)`;

            // Each reel announces its own stop when its transition ends.
            setTimeout(() => {
                reel.classList.remove('is-blurred');
                sound.reelStop();
                vibrate(10);
            }, duration);
        });

        if (nearWin) {
            this.root.classList.add('is-suspense');
            sound.suspense();
            vibrate(18);
        }

        await sleep(longest + 60);

        this.root.classList.remove('is-suspense');
        this.busy = false;
    }

    /** Gold flash on a reel, used when an upgrade for that reel is bought. */
    flash(reelIndex) {
        const reel = this.reels[reelIndex - 1];
        if (!reel) {
            return;
        }
        reel.classList.remove('is-flashing');
        void reel.offsetWidth;
        reel.classList.add('is-flashing');
        setTimeout(() => reel.classList.remove('is-flashing'), 700);
    }

    /** Screen shake for the biggest wins. */
    shake() {
        if (prefersReducedMotion()) {
            return;
        }
        this.root.classList.remove('is-shaking');
        void this.root.offsetWidth;
        this.root.classList.add('is-shaking');
        setTimeout(() => this.root.classList.remove('is-shaking'), 550);
    }

    /**
     * Visually grade each reel by how much the player has invested in it, so
     * opponents can read a build at a glance.
     *
     * @param {Array<{category:string, level:number}>} upgrades
     */
    applyBuildStyling(upgrades) {
        const levels = { 1: 0, 2: 0, 3: 0 };
        upgrades.forEach((upgrade) => {
            const match = /^reel([123])$/.exec(upgrade.category ?? '');
            if (match) {
                levels[Number(match[1])] += Number(upgrade.level ?? 1);
            }
        });
        this.reels.forEach((reel, index) => {
            reel?.setAttribute('data-boost', String(Math.min(3, levels[index + 1] ?? 0)));
        });
    }
}
