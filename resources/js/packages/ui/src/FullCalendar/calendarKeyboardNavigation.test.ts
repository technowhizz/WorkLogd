import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest';
import { createCalendarKeyboardNavigation } from './calendarKeyboardNavigation';

function press(key: string, options: KeyboardEventInit & { target?: Element } = {}) {
    const { target, ...init } = options;
    const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...init });
    (target ?? document.body).dispatchEvent(event);
    return event;
}

describe('createCalendarKeyboardNavigation', () => {
    let onPrev: Mock<() => void>;
    let onNext: Mock<() => void>;
    let navigation: ReturnType<typeof createCalendarKeyboardNavigation>;

    beforeEach(() => {
        onPrev = vi.fn(() => {});
        onNext = vi.fn(() => {});
        navigation = createCalendarKeyboardNavigation({ onPrev, onNext });
        navigation.listen();
    });

    afterEach(() => {
        navigation.stop();
        document.body.innerHTML = '';
    });

    it('pages back and forward on the arrow keys', () => {
        press('ArrowLeft');
        press('ArrowRight');

        expect(onPrev).toHaveBeenCalledTimes(1);
        expect(onNext).toHaveBeenCalledTimes(1);
    });

    it('prevents the default so the grid does not also scroll sideways', () => {
        expect(press('ArrowRight').defaultPrevented).toBe(true);
    });

    it('ignores other keys', () => {
        press('ArrowUp');
        press('a');
        press('Escape');

        expect(onPrev).not.toHaveBeenCalled();
        expect(onNext).not.toHaveBeenCalled();
    });

    it.each(['metaKey', 'ctrlKey', 'altKey', 'shiftKey'] as const)(
        'leaves %s arrows to the browser',
        (modifier) => {
            const event = press('ArrowLeft', { [modifier]: true });

            expect(onPrev).not.toHaveBeenCalled();
            expect(event.defaultPrevented).toBe(false);
        }
    );

    it.each(['input', 'textarea', 'select'])('does not page while typing in a %s', (tag) => {
        const field = document.createElement(tag);
        document.body.appendChild(field);

        press('ArrowLeft', { target: field });

        expect(onPrev).not.toHaveBeenCalled();
    });

    it('does not page while the caret is in a contenteditable', () => {
        const editable = document.createElement('div');
        editable.setAttribute('contenteditable', 'true');
        // happy-dom derives isContentEditable from the attribute only when it is focusable
        Object.defineProperty(editable, 'isContentEditable', { value: true });
        document.body.appendChild(editable);

        press('ArrowLeft', { target: editable });

        expect(onPrev).not.toHaveBeenCalled();
    });

    it.each(['dialog', 'menu', 'listbox', 'grid'])(
        'stands aside while a %s is open over the calendar',
        (role) => {
            const layer = document.createElement('div');
            layer.setAttribute('role', role);
            document.body.appendChild(layer);

            press('ArrowLeft');
            press('ArrowRight');

            expect(onPrev).not.toHaveBeenCalled();
            expect(onNext).not.toHaveBeenCalled();
        }
    );

    it('ignores an event another handler has already dealt with', () => {
        const event = new KeyboardEvent('keydown', {
            key: 'ArrowLeft',
            bubbles: true,
            cancelable: true,
        });
        event.preventDefault();
        document.body.dispatchEvent(event);

        expect(onPrev).not.toHaveBeenCalled();
    });

    it('stops listening once the calendar is gone', () => {
        navigation.stop();

        press('ArrowLeft');

        expect(onPrev).not.toHaveBeenCalled();
    });
});
