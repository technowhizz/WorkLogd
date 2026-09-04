import { usePage } from '@inertiajs/vue3';

/**
 * Whether the integration is available to this organization right now - the operator configured a
 * Google OAuth client *and* the plan includes it. Everything user facing gates on this, so a
 * self-hosted installation without a client never sees the integration at all.
 */
export function isGoogleCalendarEnabled(): boolean {
    const page = usePage<{
        google_calendar_enabled: boolean;
    }>();

    return page.props.google_calendar_enabled === true;
}

/**
 * Configured on this instance, but withheld by the organization's plan.
 *
 * Distinct from simply being off: a feature that is unavailable should be invisible, whereas one
 * the customer could have by upgrading should say so. Hiding it loses the only chance to explain
 * why it is not there.
 */
export function googleCalendarRequiresUpgrade(): boolean {
    const page = usePage<{
        google_calendar_requires_upgrade?: boolean;
    }>();

    return page.props.google_calendar_requires_upgrade === true;
}
