export type BlockEditorContext = {
    media: Konventix.Platform.Data.MediaItemData[];
    /** The brand the page belongs to; lets the picker upload into it (#292). */
    brandId?: string | null;
    events: { id: string; name: string; brandId: string }[];
};

export type BlockEditorProps<T> = {
    modelValue: T;
    /** Validation messages keyed relative to this block's `data` (e.g. `heading`). */
    errors: Record<string, string>;
    context: BlockEditorContext;
};
