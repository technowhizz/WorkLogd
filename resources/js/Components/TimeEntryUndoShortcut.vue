<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue';
import { useNotificationsStore } from '@/utils/notification';
import { useTimeEntryUndo } from '@/utils/useTimeEntryUndo';

/**
 * Ctrl+Z / Cmd+Z puts the last deleted time entry back.
 *
 * Renders nothing - it exists so the shortcut is mounted once, alongside the toast container,
 * rather than being wired up separately on every page that can delete an entry.
 */
const { undo, hasSomethingToUndo, pending } = useTimeEntryUndo();
const { addNotification } = useNotificationsStore();

/**
 * Whether the keystroke belongs to whatever is being typed in.
 *
 * Undo inside a text field is the browser's, and taking it over would mean Ctrl+Z in a
 * description restored an unrelated deletion instead of undoing the typing.
 */
function isEditing(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }

    return (
        target.isContentEditable ||
        target.tagName === 'INPUT' ||
        target.tagName === 'TEXTAREA' ||
        target.tagName === 'SELECT'
    );
}

async function onKeydown(event: KeyboardEvent) {
    if (event.key.toLowerCase() !== 'z' || event.altKey) {
        return;
    }

    // Cmd on a Mac, Ctrl everywhere else. Shift+Ctrl+Z is redo by convention, so it is left alone.
    if (!(event.metaKey || event.ctrlKey) || event.shiftKey) {
        return;
    }

    if (isEditing(event.target) || !hasSomethingToUndo()) {
        return;
    }

    event.preventDefault();

    const label = pending.value?.label ?? 'Time entry deleted';
    const restored = await undo();

    if (restored) {
        addNotification('success', label.replace('deleted', 'restored'));
    }
}

onMounted(() => document.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));
</script>

<template><span /></template>
