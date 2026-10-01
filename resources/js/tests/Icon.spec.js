import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import Icon from '@/Components/Icon.vue';
import { ICON_SIZE_CLASSES } from '@/constants';

/**
 * Icon sizing must survive the Tailwind scanner (audit I-2, 2026-08-09).
 *
 * Tailwind ships only classes it can read whole out of the source. A class
 * built at runtime — `w-${size}` — is invisible to it, so every icon size
 * worked only while some unrelated file happened to carry the same literal:
 * `w-7` (EmptyState icons) existed in exactly one admin page. The map in
 * constants.js spells each size out, and the two scans below keep it that way.
 */

const JS_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

function vueFiles(dir = JS_ROOT) {
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) return vueFiles(full);
        return entry.name.endsWith('.vue') ? [full] : [];
    });
}

describe('Icon size classes', () => {
    it('no template builds a width or height class at runtime', () => {
        const offenders = vueFiles()
            .filter((file) => /[wh]-\$\{/.test(fs.readFileSync(file, 'utf8')))
            .map((file) => path.relative(JS_ROOT, file));

        expect(offenders, 'these build Tailwind classes the scanner cannot see').toEqual([]);
    });

    it('every size passed to <Icon> has its literal in ICON_SIZE_CLASSES', () => {
        const used = new Set(['5']); // the prop default
        for (const file of vueFiles()) {
            const src = fs.readFileSync(file, 'utf8');
            for (const m of src.matchAll(/<Icon\b[^>]*?\bsize="(\d+)"/gs)) {
                used.add(m[1]);
            }
        }

        for (const size of used) {
            expect(
                ICON_SIZE_CLASSES[size],
                `size "${size}" is used in a template but has no literal in ICON_SIZE_CLASSES`,
            ).toBe('w-' + size + ' h-' + size);
        }
    });

    it('renders the mapped literal for a known size', () => {
        const wrapper = mount(Icon, { props: { name: 'home', size: '7' } });

        expect(wrapper.classes()).toContain('w-7');
        expect(wrapper.classes()).toContain('h-7');
        expect(wrapper.classes()).toContain('flex-shrink-0');
    });

    it('falls back to the default size for an unmapped value', () => {
        const wrapper = mount(Icon, { props: { name: 'home', size: '99' } });

        expect(wrapper.classes()).toContain('w-5');
        expect(wrapper.classes()).toContain('h-5');
    });
});
