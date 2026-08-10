import chroma from 'chroma-js';
import { flattenColor } from '../utils/color';

export const BREAK_COLOR = '#f59e0b';

/** Breaks read as a lighter wash than work, so the two are told apart at a glance. */
export const BREAK_BACKGROUND_MIX = 0.75;
export const EVENT_BACKGROUND_MIX = 0.65;
const BORDER_MIX = 0.5;

export type ChipColors = {
    backgroundColor: string;
    borderColor: string;
};

/**
 * The background and border of a calendar chip, derived from one base color.
 *
 * Alpha means "washed out", not "translucent": the chip is already a mix toward the theme
 * background, so passing an eight digit hex straight into chroma would emit an eight digit
 * result and let the grid lines show through wherever chips stack. Compositing onto the
 * background first honours the alpha and keeps the chip opaque, and is the identity for a
 * fully opaque color, so nothing changes for existing data.
 *
 * Shared with the drag-to-create ghost so the box you drag out is colored like the entry it
 * becomes — reimplementing the mix there would let the two drift apart.
 */
export function getChipColors(
    color: string,
    themeBackground: string,
    backgroundMix: number = EVENT_BACKGROUND_MIX
): ChipColors {
    const baseColor = flattenColor(color, themeBackground);

    // `useCssVariable` reads the theme background on mount, so the first render of a component
    // that colors something unconditionally — the selection ghost — sees an empty string, and
    // chroma throws on anything it cannot parse. Fall back to the undiluted color for that one
    // frame rather than taking the calendar down with it.
    if (!chroma.valid(themeBackground)) {
        return { backgroundColor: baseColor, borderColor: baseColor };
    }

    return {
        backgroundColor: chroma.mix(baseColor, themeBackground, backgroundMix, 'lab').hex(),
        borderColor: chroma.mix(baseColor, themeBackground, BORDER_MIX, 'lab').hex(),
    };
}
