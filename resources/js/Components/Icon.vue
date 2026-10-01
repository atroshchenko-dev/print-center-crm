<script setup>
/**
 * Icon — Reusable inline SVG icon component (Lucide-based).
 *
 * Usage: <Icon name="home" /> or <Icon name="home" class="w-6 h-6" />
 * Default size: w-5 h-5. Override via class prop.
 *
 * Sizes come from ICON_SIZE_CLASSES, never assembled at runtime from the
 * size prop: Tailwind only ships classes it can read whole out of the
 * source, so an interpolated class exists in the bundle by coincidence or
 * not at all (audit I-2). A size missing from the map falls back to the
 * default.
 */
import { computed } from 'vue'
import { ICON_SIZE_CLASSES } from '@/constants'

const props = defineProps({
    name: { type: String, required: true },
    size: { type: String, default: '5' },
})

const sizeClass = computed(() => ICON_SIZE_CLASSES[props.size] ?? ICON_SIZE_CLASSES['5'])

// ─── Lucide SVG paths ────────────────────────────────────────
const icons = {
    // Navigation
    home:           'M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8|M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z',
    'clipboard-list': '<rect x="3" y="3" width="18" height="18" rx="2"/>|M8 7h8M8 12h8M8 17h5',
    'plus-circle':  '<circle cx="12" cy="12" r="10"/>|M8 12h8M12 8v8',
    banknote:       '<rect x="2" y="6" width="20" height="12" rx="2"/>|<circle cx="12" cy="12" r="2"/>|M6 12h.01M18 12h.01',

    // Reports & Analytics
    'bar-chart':    'M3 3v18h18|M18 17V9M13 17V5M8 17v-3',
    'file-text':    'M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z|M14 2v4a1 1 0 0 0 1 1h4M10 12h4M10 16h4M10 8h1',
    mail:           '<rect width="20" height="16" x="2" y="4" rx="2"/>|m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7',
    briefcase:      'M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z|M3 6h18M16 10a4 4 0 0 1-8 0',
    'dollar-sign':  '<line x1="12" y1="1" x2="12" y2="23"/>|M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6',
    activity:       '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
    'check-circle': 'M22 11.08V12a10 10 0 1 1-5.93-9.14|<polyline points="22 4 12 14.01 9 11.01"/>',
    scroll:         'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z|M14 2v6h6M16 13H8M16 17H8M10 9H8',
    'trending-up':  '<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/>|<polyline points="16 7 22 7 22 13"/>',
    download:       'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4|<polyline points="7 10 12 15 17 10"/>|<line x1="12" y1="15" x2="12" y2="3"/>',

    // Admin
    settings:       'M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z|<circle cx="12" cy="12" r="3"/>',
    package:        'M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z|m3.3 7 8.7 5 8.7-5M12 22V12',
    'shopping-cart': '<circle cx="8" cy="21" r="1"/>|<circle cx="19" cy="21" r="1"/>|M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12',
    building:       'M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18zM6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2M10 6h4M10 10h4M10 14h4M10 18h4',
    'graduation-cap': 'M22 10v6M2 10l10-5 10 5-10 5zM6 12v5c0 1.1 2.7 2 6 2s6-.9 6-2v-5',
    users:          'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2|<circle cx="9" cy="7" r="4"/>|M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
    crown:          'M2 4l3 12h14l3-12-6 7-4-7-4 7z|M2 16h20',
    printer:        'M6 9V2h12v7|M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2|M6 14h12v8H6z',

    // Content
    folder:         'M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2z',
    zap:            '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
    'light-bulb':   '<line x1="9" y1="18" x2="15" y2="18"/>|<line x1="10" y1="22" x2="14" y2="22"/>|M15.09 14c.18-.98.65-1.74 1.41-2.5A4.65 4.65 0 0 0 18 8 6 6 0 0 0 6 8c0 1 .23 2.23 1.5 3.5A4.61 4.61 0 0 1 8.91 14',
    puzzle:         'M19.439 7.85c-.049.322.059.648.289.878l1.568 1.568c.47.47.706 1.087.706 1.704s-.235 1.233-.706 1.704l-1.611 1.611a.98.98 0 0 1-.837.276c-.47-.07-.802-.48-.968-.925a2.501 2.501 0 1 0-3.214 3.214c.446.166.855.497.925.968a.979.979 0 0 1-.276.837l-1.61 1.611a2.404 2.404 0 0 1-1.705.707 2.402 2.402 0 0 1-1.704-.706l-1.568-1.568a1.026 1.026 0 0 0-.877-.29c-.493.074-.84.504-1.02.968a2.5 2.5 0 1 1-3.237-3.237c.464-.18.894-.527.967-1.02a1.026 1.026 0 0 0-.289-.877l-1.568-1.568A2.402 2.402 0 0 1 1.998 12c0-.617.236-1.234.706-1.704L4.23 8.77c.24-.24.581-.353.917-.303.515.077.877.528 1.073 1.01a2.5 2.5 0 1 0 3.259-3.259c-.482-.196-.933-.558-1.01-1.073-.05-.336.062-.676.303-.917l1.525-1.525A2.402 2.402 0 0 1 12 2c.617 0 1.234.236 1.704.706l1.568 1.568c.23.23.556.338.877.29.493-.074.84-.504 1.02-.968a2.5 2.5 0 1 1 3.237 3.237c-.464.18-.894.527-.967 1.02Z',
    pin:            'M12 17v5|M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z',
    ruler:          'M21 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v3M21 16v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-3M4 12h16',
    scissors:       '<circle cx="6" cy="6" r="3"/>|<circle cx="6" cy="18" r="3"/>|<line x1="20" y1="4" x2="8.12" y2="15.88"/>|<line x1="14.47" y1="14.48" x2="20" y2="20"/>|<line x1="8.12" y1="8.12" x2="12" y2="12"/>',
    copy:           '<rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>|M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1',
    eye:            'M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z|<circle cx="12" cy="12" r="3"/>',
    'eye-off':      'M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24|<line x1="1" y1="1" x2="23" y2="23"/>',
    'arrow-left':   '<line x1="19" y1="12" x2="5" y2="12"/>|<polyline points="12 19 5 12 12 5"/>',
    check:          '<polyline points="20 6 9 17 4 12"/>',
    'plus':         '<line x1="12" y1="5" x2="12" y2="19"/>|<line x1="5" y1="12" x2="19" y2="12"/>',
    'minus':        '<line x1="5" y1="12" x2="19" y2="12"/>',

    // Order types
    'building-columns': 'M2 20h20|M4 20V8|M20 20V8|M12 4l10 4H2z|M8 12v4M12 12v4M16 12v4',

    // Shift & Cash
    wallet:         'M20 12V8a2 2 0 0 0-2-2H6a2 2 0 0 1-2-2 2 2 0 0 1 2-2h12v4|M4 6v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2|M18 12a2 2 0 0 0 0 4h4v-4z',
    database:       '<ellipse cx="12" cy="5" rx="9" ry="3"/>|M21 12c0 1.66-4 3-9 3s-9-1.34-9-3|M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5',

    // Palette & Colors
    palette:        'M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z',
    'half-circle':  '<circle cx="12" cy="12" r="10"/>|M12 2a10 10 0 0 1 0 20z',
    file:           'M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5z|<polyline points="14 2 14 8 20 8"/>',
}

function renderPaths(iconDef) {
    if (!iconDef) return ''
    return iconDef.split('|').map(p => {
        p = p.trim()
        if (p.startsWith('<')) return p  // raw element (circle, rect, polyline, line, ellipse)
        return `<path d="${p}"/>`
    }).join('')
}
</script>

<template>
    <svg
        :class="[sizeClass, 'flex-shrink-0']"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        v-html="renderPaths(icons[name] || icons['file'])"
    />
</template>
