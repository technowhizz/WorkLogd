import { describe, expect, it } from 'vitest';
import { computed } from 'vue';
import { mount } from '@vue/test-utils';
import FullCalendarEventTooltip from './FullCalendarEventTooltip.vue';
import {
    EXTERNAL_REFERENCE_DETECTOR,
    type ExternalReferenceDetector,
} from '../TimeEntry/externalSyncTypes';
import type { Organization } from '@/packages/api/src';

const LONG_DESCRIPTION =
    'RT-2735 Test sanger ssh access + create ticketing account + create support ticket ' +
    'for no access + reply again + test again';

type OrganizationOverrides = Partial<
    Pick<Organization, 'time_format' | 'interval_format' | 'number_format'>
>;

/**
 * Stand-in for the app's Jira detector: this package is told how to find a reference rather
 * than knowing what one is, so the test injects a rule instead of a provider.
 */
const KEY_PATTERN = /\b([A-Z][A-Z0-9]+-\d+)\b/;
const detectKey: ExternalReferenceDetector = (description) => {
    const match = description?.match(KEY_PATTERN);
    return match ? { label: match[1]! } : null;
};

function mountTooltip(
    props: Partial<InstanceType<typeof FullCalendarEventTooltip>['$props']> = {},
    organization: OrganizationOverrides = {},
    /** Absent means the host tracks no external references at all. */
    detector: ExternalReferenceDetector | null = null
) {
    return mount(FullCalendarEventTooltip, {
        props: {
            title: LONG_DESCRIPTION,
            start: '2026-07-14T10:00:00Z',
            end: '2026-07-14T11:30:00Z',
            durationSeconds: 90 * 60,
            ...props,
        },
        global: {
            provide: {
                organization: computed(() => ({
                    time_format: '24-hours',
                    interval_format: 'hours-minutes',
                    number_format: 'point',
                    ...organization,
                })),
                [EXTERNAL_REFERENCE_DETECTOR]: computed(() => detector),
            },
        },
    });
}

const reference = (wrapper: ReturnType<typeof mountTooltip>) =>
    wrapper.find('[data-testid="calendar_event_tooltip_reference"]');

describe('FullCalendarEventTooltip', () => {
    it('renders the description in full, unclamped', () => {
        const wrapper = mountTooltip();
        const title = wrapper.get('.font-semibold');

        expect(title.text()).toBe(LONG_DESCRIPTION);
        // The event block clamps with -webkit-line-clamp / .fc-event-title; showing the whole
        // description is the entire point of this popup, so neither may leak in here.
        expect(wrapper.html()).not.toContain('fc-event-title');
        expect(title.attributes('style')).toBeUndefined();
    });

    it('renders the time range and the duration', () => {
        const text = mountTooltip().text();

        expect(text).toContain('10:00 - 11:30');
        expect(text).toContain('1h 30min');
    });

    it('renders an open-ended range for a running entry', () => {
        const text = mountTooltip({ end: null }).text();

        expect(text).toContain('10:00 - ...');
    });

    it('respects the organization time format', () => {
        const text = mountTooltip({}, { time_format: '12-hours' }).text();

        expect(text).toContain('10:00 AM - 11:30 AM');
    });

    it('respects the organization interval format', () => {
        const text = mountTooltip({}, { interval_format: 'decimal' }).text();

        expect(text).toContain('1.5 h');
    });

    it('renders the project, task and client when present', () => {
        const text = mountTooltip({
            projectName: 'Acme Redesign',
            taskName: 'Homepage',
            clientName: 'Acme Inc',
        }).text();

        expect(text).toContain('Acme Redesign');
        expect(text).toContain('Homepage');
        expect(text).toContain('Acme Inc');
    });

    it('omits the meta block when there is no project, task or client', () => {
        const wrapper = mountTooltip();

        // Title, then the time/duration row — no meta block in between.
        expect(wrapper.get('[data-testid="calendar_event_tooltip"]').element.children).toHaveLength(
            2
        );
    });
});

/*
 * The ticket an entry logs against is worth seeing before opening it, but this package must not
 * learn what a ticket is: it asks the injected detector, exactly as the edit dialog does.
 */
describe('FullCalendarEventTooltip external reference', () => {
    it('shows the reference the host detects in the description', () => {
        const wrapper = mountTooltip({ description: 'RT-2735 ssh access' }, {}, detectKey);

        expect(reference(wrapper).text()).toBe('RT-2735');
    });

    it('reads the description rather than the displayed title', () => {
        // The block's title can be decorated ("Break · …") or invented ("No description"),
        // so detecting on it would find keys the entry does not have and miss ones it does.
        const wrapper = mountTooltip(
            { title: 'No description', description: 'RT-2735 ssh access' },
            {},
            detectKey
        );

        expect(reference(wrapper).text()).toBe('RT-2735');
    });

    it('shows nothing when the detector finds no reference', () => {
        const wrapper = mountTooltip({ description: 'no ticket here' }, {}, detectKey);

        expect(reference(wrapper).exists()).toBe(false);
    });

    it('shows nothing when the description is absent', () => {
        const wrapper = mountTooltip({}, {}, detectKey);

        expect(reference(wrapper).exists()).toBe(false);
    });

    /*
     * No detector means the host tracks no references at all — a different thing from a detector
     * that finds none, and the reason the popup never says "No ticket" the way the dialog does.
     */
    it('leaves an entry untouched when nothing is injected, key or no key', () => {
        const wrapper = mountTooltip({ description: 'RT-2735 ssh access' });

        expect(reference(wrapper).exists()).toBe(false);
        expect(wrapper.get('[data-testid="calendar_event_tooltip"]').element.children).toHaveLength(
            2
        );
    });

    /*
     * The edit dialog's chip carries `time_entry_external_reference`, and e2e looks it up
     * unscoped — sharing the hook would make an open popup a second match for it.
     */
    it('carries its own testid rather than the edit dialog one', () => {
        const wrapper = mountTooltip({ description: 'RT-2735 ssh access' }, {}, detectKey);

        expect(wrapper.find('[data-testid="time_entry_external_reference"]').exists()).toBe(false);
    });
});
