import { describe, expect, it } from 'vitest';
import { BREAK_BACKGROUND_MIX, EVENT_BACKGROUND_MIX, getChipColors } from './eventColors';

const LIGHT_BACKGROUND = '#f5f5f5';

describe('getChipColors', () => {
    it('mixes toward the theme background', () => {
        const { backgroundColor, borderColor } = getChipColors('#26a69a', LIGHT_BACKGROUND);

        // Pinned to the values the calendar has always rendered, so a refactor of the mix
        // cannot quietly agree with itself and still change what users see.
        expect(backgroundColor).toBe('#b6dad4');
        expect(borderColor).toBe('#9bcec6');
    });

    it('washes a break out further than a work entry', () => {
        const work = getChipColors('#f59e0b', LIGHT_BACKGROUND, EVENT_BACKGROUND_MIX);
        const brk = getChipColors('#f59e0b', LIGHT_BACKGROUND, BREAK_BACKGROUND_MIX);

        expect(brk.backgroundColor).not.toBe(work.backgroundColor);
        // Same base and the same border mix — only the fill differs.
        expect(brk.borderColor).toBe(work.borderColor);
    });

    /*
     * The theme background is read from a CSS variable on mount, so anything that colors itself
     * unconditionally gets one frame with an empty string. chroma throws on that, which used to
     * mean a blank calendar rather than a slightly-off ghost.
     */
    it('falls back to the undiluted color when the background is not yet known', () => {
        expect(getChipColors('#6b7280', '')).toEqual({
            backgroundColor: '#6b7280',
            borderColor: '#6b7280',
        });
    });

    it('composites a translucent color onto the background before mixing', () => {
        const translucent = getChipColors('#ef535080', LIGHT_BACKGROUND);

        expect(translucent.backgroundColor).toHaveLength(7);
        expect(translucent.borderColor).toHaveLength(7);
    });
});
