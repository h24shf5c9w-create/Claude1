<?php
/**
 * Inline SVG sprite for the ten reel symbols (plus Wild).
 *
 * Deliberately not emoji: one shared 64x64 grid, one shared shading language
 * (`.s-hi` highlight, `.s-lo` shadow, body inherits `currentColor` so the CSS
 * gives each symbol its own metal/gem hue). Rendering a reel is then just
 * `<use href="#sym-crown">`, which keeps the DOM tiny during spin animations.
 */
?>
<svg class="symbol-sprite" aria-hidden="true" focusable="false" width="0" height="0">
    <defs>
        <linearGradient id="symShine" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#fff" stop-opacity=".45"/>
            <stop offset="60%" stop-color="#fff" stop-opacity="0"/>
        </linearGradient>

        <!-- 1. Cherry -->
        <symbol id="sym-cherry" viewBox="0 0 64 64">
            <path class="s-stem" d="M32 10c-6 8-14 14-18 24" fill="none" stroke="#6ba54a" stroke-width="3.4" stroke-linecap="round"/>
            <path class="s-stem" d="M32 10c4 9 8 14 12 22" fill="none" stroke="#6ba54a" stroke-width="3.4" stroke-linecap="round"/>
            <path class="s-leaf" d="M32 10c5-5 12-6 16-3-3 5-9 8-16 3z" fill="#7cc356"/>
            <circle class="s-main" cx="20" cy="45" r="11" fill="currentColor"/>
            <circle class="s-main" cx="45" cy="47" r="10" fill="currentColor"/>
            <circle class="s-hi" cx="16" cy="41" r="3.4" fill="#fff" opacity=".5"/>
            <circle class="s-hi" cx="42" cy="43" r="3" fill="#fff" opacity=".45"/>
        </symbol>

        <!-- 2. Lemon -->
        <symbol id="sym-lemon" viewBox="0 0 64 64">
            <path class="s-main" d="M12 32c0-11 9-19 20-19s20 8 20 19-9 19-20 19-20-8-20-19z" fill="currentColor"/>
            <path class="s-lo" d="M32 13c11 0 20 8 20 19s-9 19-20 19c9-4 13-11 13-19s-4-15-13-19z" fill="#000" opacity=".14"/>
            <path class="s-hi" d="M20 25c3-5 8-8 13-8-5 3-9 6-11 11-1 2-4 1-2-3z" fill="#fff" opacity=".55"/>
            <path d="M52 30c3-1 5 0 6 2-2 2-4 2-6 1z" fill="#7cc356"/>
        </symbol>

        <!-- 3. Bell -->
        <symbol id="sym-bell" viewBox="0 0 64 64">
            <path class="s-main" d="M32 8a5 5 0 0 1 5 5v1c8 3 13 11 13 20v9l4 6H10l4-6v-9c0-9 5-17 13-20v-1a5 5 0 0 1 5-5z" fill="currentColor"/>
            <path class="s-lo" d="M32 8a5 5 0 0 1 5 5v1c8 3 13 11 13 20v9l4 6H40l3-6v-9c0-9-4-17-11-20z" fill="#000" opacity=".16"/>
            <path class="s-hi" d="M22 22c2-4 5-6 8-7-4 4-6 8-7 13-1 3-3 1-1-6z" fill="#fff" opacity=".5"/>
            <circle cx="32" cy="53" r="5" fill="currentColor"/>
            <circle cx="32" cy="53" r="5" fill="#000" opacity=".18"/>
        </symbol>

        <!-- 4. Horseshoe -->
        <symbol id="sym-horseshoe" viewBox="0 0 64 64">
            <path class="s-main" d="M32 7c12 0 20 9 20 21 0 10-4 17-4 24h-9c0-8 4-14 4-23 0-7-4-12-11-12s-11 5-11 12c0 9 4 15 4 23h-9c0-7-4-14-4-24C12 16 20 7 32 7z" fill="currentColor"/>
            <path class="s-hi" d="M32 7c-9 0-16 6-18 15 4-6 10-9 18-9z" fill="#fff" opacity=".4"/>
            <circle cx="19" cy="47" r="2.2" fill="#000" opacity=".35"/>
            <circle cx="45" cy="47" r="2.2" fill="#000" opacity=".35"/>
            <circle cx="16" cy="27" r="2.2" fill="#000" opacity=".35"/>
            <circle cx="48" cy="27" r="2.2" fill="#000" opacity=".35"/>
        </symbol>

        <!-- 5. Star -->
        <symbol id="sym-star" viewBox="0 0 64 64">
            <path class="s-main" d="M32 5l7.6 17.2L58 24.4 44.4 36.8 48.2 55 32 45.6 15.8 55l3.8-18.2L6 24.4l18.4-2.2z" fill="currentColor"/>
            <path class="s-lo" d="M32 5l7.6 17.2L58 24.4 44.4 36.8 48.2 55 32 45.6z" fill="#000" opacity=".13"/>
            <path class="s-hi" d="M32 12l4 9-9-2z" fill="#fff" opacity=".55"/>
        </symbol>

        <!-- 6. Money Bag -->
        <symbol id="sym-moneybag" viewBox="0 0 64 64">
            <path d="M24 8h16l-4 7H28z" fill="#d7c49a"/>
            <path class="s-main" d="M28 15h8c10 6 17 16 17 26 0 9-9 15-21 15s-21-6-21-15c0-10 7-20 17-26z" fill="currentColor"/>
            <path class="s-lo" d="M36 15c10 6 17 16 17 26 0 9-9 15-21 15 8-3 13-9 13-16 0-9-4-19-9-25z" fill="#000" opacity=".16"/>
            <path d="M32 26v3m0 15v3" stroke="#ffe9ae" stroke-width="2.6" stroke-linecap="round"/>
            <path d="M37 31c-1-3-3-4-5-4-3 0-5 2-5 4 0 5 10 3 10 8 0 3-2 5-5 5s-5-2-5-4" fill="none" stroke="#ffe9ae" stroke-width="3" stroke-linecap="round"/>
        </symbol>

        <!-- 7. Crystal -->
        <symbol id="sym-crystal" viewBox="0 0 64 64">
            <path class="s-main" d="M32 4l16 14v28L32 60 16 46V18z" fill="currentColor"/>
            <path class="s-hi" d="M32 4l16 14-16 8-16-8z" fill="#fff" opacity=".42"/>
            <path class="s-lo" d="M48 18v28L32 60V26z" fill="#000" opacity=".2"/>
            <path d="M32 26v34" stroke="#fff" stroke-width="1.4" opacity=".3"/>
        </symbol>

        <!-- 8. Diamond -->
        <symbol id="sym-diamond" viewBox="0 0 64 64">
            <path class="s-main" d="M14 14h36l10 12-28 30L4 26z" fill="currentColor"/>
            <path class="s-hi" d="M14 14h36l10 12H4z" fill="#fff" opacity=".38"/>
            <path class="s-lo" d="M32 56L60 26H46z" fill="#000" opacity=".22"/>
            <path d="M14 14l6 12 12-12 12 12 6-12M4 26h56M20 26l12 30 12-30" fill="none" stroke="#fff" stroke-width="1.5" opacity=".45"/>
        </symbol>

        <!-- 9. Crown -->
        <symbol id="sym-crown" viewBox="0 0 64 64">
            <path class="s-main" d="M6 20l11 10 15-19 15 19 11-10-5 30H11z" fill="currentColor"/>
            <path class="s-lo" d="M32 11l15 19 11-10-5 30H32z" fill="#000" opacity=".14"/>
            <rect x="10" y="51" width="44" height="8" rx="2.6" fill="currentColor"/>
            <rect x="10" y="51" width="44" height="8" rx="2.6" fill="#000" opacity=".15"/>
            <circle cx="32" cy="34" r="4" fill="#ff5f7e"/>
            <circle cx="16" cy="36" r="3" fill="#57d1ff"/>
            <circle cx="48" cy="36" r="3" fill="#57d1ff"/>
            <path class="s-hi" d="M6 20l11 10 4-5-9-8z" fill="#fff" opacity=".4"/>
        </symbol>

        <!-- 10. Royal Trophy -->
        <symbol id="sym-trophy" viewBox="0 0 64 64">
            <path d="M14 12h36v12c0 11-8 19-18 19s-18-8-18-19z" class="s-main" fill="currentColor"/>
            <path class="s-lo" d="M32 43c10 0 18-8 18-19V12H36v12c0 8-2 15-8 19z" fill="#000" opacity=".16"/>
            <path d="M14 16H8a8 8 0 0 0 8 12M50 16h6a8 8 0 0 1-8 12" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round"/>
            <rect x="28" y="42" width="8" height="8" fill="currentColor"/>
            <rect x="18" y="50" width="28" height="8" rx="2.6" fill="currentColor"/>
            <rect x="18" y="50" width="28" height="8" rx="2.6" fill="#000" opacity=".18"/>
            <path class="s-hi" d="M20 16h5v8c0 4 1 8 3 11-6-3-8-9-8-14z" fill="#fff" opacity=".45"/>
            <circle cx="32" cy="24" r="4.5" fill="#fff" opacity=".55"/>
        </symbol>

        <!-- Wild (only appears with the Wild Chance upgrade) -->
        <symbol id="sym-wild" viewBox="0 0 64 64">
            <path class="s-main" d="M32 4l8 12 14-4-4 14 12 8-12 8 4 14-14-4-8 12-8-12-14 4 4-14L2 34l12-8-4-14 14 4z" fill="currentColor"/>
            <circle cx="32" cy="34" r="10" fill="#fff" opacity=".28"/>
            <path d="M25 30l3 10 4-7 4 7 3-10" fill="none" stroke="#fff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>
        </symbol>

        <!-- Upgrade card icons -->
        <symbol id="ico-dice" viewBox="0 0 64 64">
            <rect x="8" y="8" width="48" height="48" rx="12" fill="currentColor"/>
            <circle cx="21" cy="21" r="5" fill="#12161f"/>
            <circle cx="43" cy="43" r="5" fill="#12161f"/>
            <circle cx="32" cy="32" r="5" fill="#12161f"/>
        </symbol>
        <symbol id="ico-coin" viewBox="0 0 64 64">
            <circle cx="32" cy="32" r="24" fill="currentColor"/>
            <circle cx="32" cy="32" r="17" fill="#000" opacity=".18"/>
            <path d="M37 26c-1-2-3-3-5-3-3 0-5 2-5 4 0 5 11 3 11 9 0 3-3 5-6 5s-5-1-6-3M32 18v4m0 20v4"
                  fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round"/>
        </symbol>
        <symbol id="ico-sparkle" viewBox="0 0 64 64">
            <path d="M32 4l6 20 20 8-20 6-6 22-6-22-20-6 20-8z" fill="currentColor"/>
            <path d="M52 6l2 7 7 2-7 3-2 7-3-7-7-3 7-2z" fill="currentColor" opacity=".7"/>
        </symbol>
        <symbol id="ico-magnet" viewBox="0 0 64 64">
            <path d="M14 46V28a18 18 0 0 1 36 0v18H38V28a6 6 0 0 0-12 0v18z" fill="currentColor"/>
            <rect x="14" y="46" width="12" height="11" fill="#ff5f7e"/>
            <rect x="38" y="46" width="12" height="11" fill="#57d1ff"/>
        </symbol>
    </defs>
</svg>
