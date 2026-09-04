import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import AutoGrowTextarea from './AutoGrowTextarea.vue';

describe('AutoGrowTextarea', () => {
    it('emits submit on Enter, so the existing save-on-Enter habit still works', async () => {
        const wrapper = mount(AutoGrowTextarea, { props: { modelValue: 'a thing' } });

        await wrapper.find('textarea').trigger('keydown', { key: 'Enter', shiftKey: false });

        expect(wrapper.emitted('submit')).toHaveLength(1);
    });

    it('does not submit on Shift+Enter, which is how a newline is typed', async () => {
        const wrapper = mount(AutoGrowTextarea, { props: { modelValue: 'a thing' } });

        await wrapper.find('textarea').trigger('keydown', { key: 'Enter', shiftKey: true });

        expect(wrapper.emitted('submit')).toBeUndefined();
    });

    it('leaves other keys alone', async () => {
        const wrapper = mount(AutoGrowTextarea, { props: { modelValue: '' } });

        await wrapper.find('textarea').trigger('keydown', { key: 'a' });

        expect(wrapper.emitted('submit')).toBeUndefined();
    });

    it('keeps multi-line text intact through the model', async () => {
        const wrapper = mount(AutoGrowTextarea, {
            props: { modelValue: 'first line\nsecond line' },
        });

        expect((wrapper.find('textarea').element as HTMLTextAreaElement).value).toBe(
            'first line\nsecond line'
        );
    });

    it('renders as a textarea rather than an input, so newlines can exist at all', () => {
        const wrapper = mount(AutoGrowTextarea, { props: { modelValue: '' } });

        expect(wrapper.find('textarea').exists()).toBe(true);
        expect(wrapper.find('input').exists()).toBe(false);
    });
});
