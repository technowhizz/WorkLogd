/**
 * Formatting helpers shared by the admin screens.
 *
 * These deliberately do not go through the app's organization-aware formatters: the portal spans
 * every organization at once, so there is no single date, time or currency format to honour. ISO
 * dates and explicit currency codes are unambiguous to whoever is administering the instance.
 */

export function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '--';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? '--'
        : date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

export function formatDateTime(value: string | null | undefined): string {
    if (!value) {
        return '--';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? '--'
        : date.toLocaleString(undefined, {
              year: 'numeric',
              month: 'short',
              day: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          });
}

/** Minor units to a readable amount, e.g. 2500 GBP -> "25.00 GBP". */
export function formatMoney(minor: number | null | undefined, currency: string | null): string {
    if (minor === null || minor === undefined) {
        return '--';
    }

    return (
        (minor / 100).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }) + (currency ? ' ' + currency : '')
    );
}

/** How far off a date is, in plain words - "in 5 days", "3 days ago". */
export function formatRelative(value: string | null | undefined): string {
    if (!value) {
        return '--';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '--';
    }

    const days = Math.round((date.getTime() - Date.now()) / 86_400_000);

    if (days === 0) {
        return 'today';
    }

    return days > 0 ? `in ${days} day${days === 1 ? '' : 's'}` : `${-days} days ago`;
}
