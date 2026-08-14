import { computed } from 'vue';
import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import FullCalendarDayHeader from './FullCalendarDayHeader.vue';
import { getLocalizedDayJs } from '../utils/time';

function mountHeader(syncStatus: 'synced' | 'attention' | null) {
    return mount(FullCalendarDayHeader, {
        props: {
            date: getLocalizedDayJs('2026-08-10T00:00:00Z'),
            totalSeconds: 3600,
            syncStatus,
        },
        global: {
            provide: { organization: computed(() => undefined) },
        },
    });
}

describe('FullCalendarDayHeader sync dot', () => {
    it('shows nothing when the day has no sync state', () => {
        const wrapper = mountHeader(null);

        expect(wrapper.find('[data-testid="day_sync_indicator"]').exists()).toBe(false);
    });

    it('shows a green dot when everything on the day is logged', () => {
        const dot = mountHeader('synced').get('[data-testid="day_sync_indicator"]');

        expect(dot.attributes('data-sync-state')).toBe('synced');
        expect(dot.classes()).toContain('bg-green-500');
        expect(dot.attributes('title')).toContain('is logged');
    });

    it('shows a grey dot when something on the day is not logged', () => {
        const dot = mountHeader('attention').get('[data-testid="day_sync_indicator"]');

        expect(dot.attributes('data-sync-state')).toBe('attention');
        expect(dot.classes()).toContain('bg-text-quaternary');
        expect(dot.attributes('title')).toContain('not logged');
    });
});
