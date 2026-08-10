import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import JiraSyncButton from './JiraSyncButton.vue';
import JiraSyncDialog from './JiraSyncDialog.vue';

function mountButton(props: {
    isConnected: boolean;
    startDate: string | null;
    endDate: string | null;
}) {
    return mount(JiraSyncButton, { props, shallow: true });
}

describe('JiraSyncButton', () => {
    /*
     * The half open to inclusive conversion used to live here and now lives in Pages/Calendar.vue,
     * so that the sync dialog and the indicator query cannot disagree about what `end` means. What
     * matters here is that the button does not convert a second time. The conversion itself is
     * covered end to end in e2e/jira.spec.ts, which asserts the dates the sync-preview request
     * actually carries, including with calendar_week_days = 5.
     */
    it('passes the inclusive range straight through to the dialog', () => {
        const wrapper = mountButton({
            isConnected: true,
            startDate: '2026-08-10',
            endDate: '2026-08-16',
        });

        const dialog = wrapper.findComponent(JiraSyncDialog);
        expect(dialog.props('startDate')).toBe('2026-08-10');
        expect(dialog.props('endDate')).toBe('2026-08-16');
    });

    it('renders no dialog until the calendar has a range', () => {
        const wrapper = mountButton({ isConnected: true, startDate: null, endDate: null });

        expect(wrapper.findComponent(JiraSyncDialog).exists()).toBe(false);
    });
});
