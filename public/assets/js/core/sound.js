/**
 * Sound manager.
 *
 * Tones are synthesised with the Web Audio API rather than shipping audio
 * files — no extra requests, no licensing, and it stays tiny. The AudioContext
 * is only created after a real user gesture, which is what browser autoplay
 * policies require. Muting is remembered in localStorage.
 *
 * The game is fully playable with sound off or unsupported.
 */

const STORAGE_KEY = 'royalspin.sound';

class SoundManager {
    constructor() {
        this.context = null;
        this.enabled = localStorage.getItem(STORAGE_KEY) !== 'off';
        this.master = null;
    }

    /** Must be called from inside a user-gesture handler. */
    unlock() {
        if (this.context || !window.AudioContext) {
            this.context?.resume?.();
            return;
        }
        try {
            this.context = new AudioContext();
            this.master = this.context.createGain();
            this.master.gain.value = 0.22;
            this.master.connect(this.context.destination);
        } catch {
            this.context = null;
        }
    }

    setEnabled(enabled) {
        this.enabled = enabled;
        localStorage.setItem(STORAGE_KEY, enabled ? 'on' : 'off');
    }

    toggle() {
        this.setEnabled(!this.enabled);
        return this.enabled;
    }

    /** @param {{freq:number, to?:number, dur?:number, type?:OscillatorType, gain?:number, delay?:number}} spec */
    tone({ freq, to, dur = 0.12, type = 'triangle', gain = 1, delay = 0 }) {
        if (!this.enabled || !this.context) {
            return;
        }
        const start = this.context.currentTime + delay;
        const oscillator = this.context.createOscillator();
        const envelope = this.context.createGain();

        oscillator.type = type;
        oscillator.frequency.setValueAtTime(freq, start);
        if (to) {
            oscillator.frequency.exponentialRampToValueAtTime(Math.max(1, to), start + dur);
        }

        envelope.gain.setValueAtTime(0.0001, start);
        envelope.gain.exponentialRampToValueAtTime(gain, start + 0.012);
        envelope.gain.exponentialRampToValueAtTime(0.0001, start + dur);

        oscillator.connect(envelope);
        envelope.connect(this.master);
        oscillator.start(start);
        oscillator.stop(start + dur + 0.02);
    }

    chord(frequencies, dur = 0.3, type = 'triangle') {
        frequencies.forEach((freq, index) =>
            this.tone({ freq, dur, type, gain: 0.7, delay: index * 0.055 }));
    }

    /* ---- named hooks used across the game ---- */

    button()      { this.tone({ freq: 420, to: 300, dur: 0.07, type: 'square', gain: 0.5 }); }
    dice()        { this.tone({ freq: 180, to: 620, dur: 0.28, type: 'sawtooth', gain: 0.5 }); }
    reelSpin()    { this.tone({ freq: 130, to: 190, dur: 0.5, type: 'sawtooth', gain: 0.25 }); }
    reelStop()    { this.tone({ freq: 620, to: 380, dur: 0.09, type: 'square', gain: 0.6 }); }
    suspense()    { this.tone({ freq: 220, to: 500, dur: 0.85, type: 'sine', gain: 0.5 }); }
    pair()        { this.chord([523, 659], 0.22); }
    triple()      { this.chord([523, 659, 784, 1046], 0.34); }
    crown()       { this.chord([659, 784, 988, 1319], 0.45, 'square'); }
    trophy()      { this.chord([784, 988, 1175, 1568, 2093], 0.55, 'square'); }
    lose()        { this.tone({ freq: 200, to: 140, dur: 0.16, type: 'sine', gain: 0.35 }); }
    upgrade()     { this.chord([440, 660, 880], 0.26, 'triangle'); }
    victory()     { this.chord([523, 659, 784, 1046, 1319], 0.7, 'triangle'); }
    turn()        { this.tone({ freq: 500, to: 700, dur: 0.14, type: 'sine', gain: 0.4 }); }
}

export const sound = new SoundManager();

/** Wires every `[data-sound-toggle]` button and unlocks audio on first tap. */
export function initSoundControls() {
    const unlockOnce = () => sound.unlock();
    document.addEventListener('pointerdown', unlockOnce, { once: true });
    document.addEventListener('keydown', unlockOnce, { once: true });

    document.querySelectorAll('[data-sound-toggle]').forEach((button) => {
        const paint = () => {
            const on = sound.enabled;
            button.setAttribute('aria-pressed', String(on));
            button.querySelector('[data-sound-on]')?.toggleAttribute('hidden', !on);
            button.querySelector('[data-sound-off]')?.toggleAttribute('hidden', on);
        };
        paint();
        button.addEventListener('click', () => {
            sound.unlock();
            sound.toggle();
            paint();
            if (sound.enabled) {
                sound.button();
            }
        });
    });
}
