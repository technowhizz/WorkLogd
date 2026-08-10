/**
 * Left and right arrows page the calendar, taking the same step the toolbar's buttons do — a week
 * in week view, a day in day view — because they call the very same handlers.
 *
 * A plain factory rather than a composable so it can be driven directly in tests, the same way
 * createEscapeCancel is. The owning component calls `listen()` on mount and `stop()` on unmount,
 * which is also what scopes it to the calendar: nothing else in the app has these bindings.
 */

/** Elements whose own handling of the arrow keys must win. */
const TYPING_TAGS = ['INPUT', 'TEXTAREA', 'SELECT'];

/**
 * Anything layered over the calendar owns the arrows while it is open — a dialog's fields, a
 * dropdown's list, the date picker's grid. Asking the document is deliberate: the alternative is
 * for this to know about every layer that can open, and it would fall behind the first time a new
 * one is added.
 */
const LAYER_SELECTOR = '[role="dialog"], [role="menu"], [role="listbox"], [role="grid"]';

export function createCalendarKeyboardNavigation(callbacks: {
    onPrev: () => void;
    onNext: () => void;
}) {
    function isTyping(target: EventTarget | null): boolean {
        if (!(target instanceof HTMLElement)) {
            return false;
        }

        return target.isContentEditable || TYPING_TAGS.includes(target.tagName);
    }

    function onKeyDown(event: KeyboardEvent): void {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
            return;
        }

        // Modified arrows belong to the browser and the OS: history back/forward, word-wise
        // selection, and so on. Paging on top of those would be a surprise.
        if (event.metaKey || event.ctrlKey || event.altKey || event.shiftKey) {
            return;
        }

        if (event.defaultPrevented || isTyping(event.target)) {
            return;
        }

        if (document.querySelector(LAYER_SELECTOR) !== null) {
            return;
        }

        // The time grid scrolls horizontally when it is narrower than its content, so without
        // this a keypress would both page and nudge the scroll position.
        event.preventDefault();

        if (event.key === 'ArrowLeft') {
            callbacks.onPrev();
        } else {
            callbacks.onNext();
        }
    }

    return {
        onKeyDown,
        listen: () => document.addEventListener('keydown', onKeyDown),
        stop: () => document.removeEventListener('keydown', onKeyDown),
    };
}
