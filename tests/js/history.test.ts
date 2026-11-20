import { describe, expect, it } from 'vitest';
import { EditorHistory } from '../../resources/js/editor/history';

describe('EditorHistory undo/redo stack', () => {
    it('supports commit, undo, redo, branch truncation, and max capacity', () => {
        const history = new EditorHistory({ count: 0 }, 3);
        expect(history.canUndo()).toBe(false);
        expect(history.canRedo()).toBe(false);

        // No-op duplicate commit
        history.commit({ count: 0 });
        expect(history.canUndo()).toBe(false);

        history.commit({ count: 1 });
        history.commit({ count: 2 });
        history.commit({ count: 3 });
        history.commit({ count: 4 }); // pushes out count: 0 due to maxEntries=3

        expect(history.canUndo()).toBe(true);
        expect(history.undo()).toEqual({ count: 3 });
        expect(history.undo()).toEqual({ count: 2 });
        expect(history.canRedo()).toBe(true);
        expect(history.redo()).toEqual({ count: 3 });

        // Committing a new branch clears redo stack
        history.commit({ count: 99 });
        expect(history.canRedo()).toBe(false);
        expect(history.undo()).toEqual({ count: 3 });
    });
});
