/**
 * Immutable snapshot undo/redo stack for the drag-and-drop map editor.
 */
export class EditorHistory<T> {
    private past: string[] = [];
    private future: string[] = [];
    private current: string;

    constructor(
        initialState: T,
        private readonly maxEntries = 80,
    ) {
        this.current = JSON.stringify(initialState);
    }

    public reset(state: T): void {
        this.past = [];
        this.future = [];
        this.current = JSON.stringify(state);
    }

    public commit(nextState: T): void {
        const serialized = JSON.stringify(nextState);
        if (serialized === this.current) {
            return;
        }
        this.past.push(this.current);
        if (this.past.length > this.maxEntries) {
            this.past.shift();
        }
        this.current = serialized;
        this.future = [];
    }

    public canUndo(): boolean {
        return this.past.length > 0;
    }

    public canRedo(): boolean {
        return this.future.length > 0;
    }

    public undo(): T | null {
        const previous = this.past.pop();
        if (previous === undefined) {
            return null;
        }
        this.future.push(this.current);
        this.current = previous;
        return JSON.parse(this.current) as T;
    }

    public redo(): T | null {
        const next = this.future.pop();
        if (next === undefined) {
            return null;
        }
        this.past.push(this.current);
        this.current = next;
        return JSON.parse(this.current) as T;
    }
}
