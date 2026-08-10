import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { CalendarDate } from '@internationalized/date';
import DateRangePicker from './DateRangePicker.vue';
import { RangeCalendar } from '../range-calendar';

async function openPicker() {
    const wrapper = mount(DateRangePicker, {
        props: { start: '', end: '' },
        attachTo: document.body,
    });
    await wrapper.get('button').trigger('click');

    return wrapper;
}

describe('DateRangePicker', () => {
    it('closes once both ends of the range have been picked', async () => {
        const wrapper = await openPicker();
        const calendar = wrapper.findComponent(RangeCalendar);
        expect(calendar.exists()).toBe(true);

        calendar.vm.$emit('update:modelValue', {
            start: new CalendarDate(2026, 8, 10),
            end: new CalendarDate(2026, 8, 16),
        });
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('update:start')).toHaveLength(1);
        expect(wrapper.emitted('update:end')).toHaveLength(1);
        // Closing is what emits submit, so this is also the consumers' refresh signal
        expect(wrapper.emitted('submit')).toHaveLength(1);
        expect(wrapper.get('button').attributes('aria-expanded')).toBe('false');

        wrapper.unmount();
    });

    it('stays open on the first click of a two click range', async () => {
        const wrapper = await openPicker();

        // reka reports the half chosen range as a start with no end
        wrapper.findComponent(RangeCalendar).vm.$emit('update:modelValue', {
            start: new CalendarDate(2026, 8, 10),
            end: undefined,
        });
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('update:end')).toEqual([['']]);
        expect(wrapper.emitted('submit')).toBeUndefined();
        expect(wrapper.get('button').attributes('aria-expanded')).toBe('true');

        wrapper.unmount();
    });
});
