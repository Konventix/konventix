/**
 * What the programme says under a called-off entry (D-AGD-08, which
 * applies to a competition block exactly as to an item: it stays, struck).
 *
 * An item says "Fällt aus" with the organizer's reason when there is one.
 * A block says "Abgesagt": Competition sends no reason, and the tournament
 * is called off by whoever runs it, not by the person who wrote the
 * programme.
 */
export type CancellableCell = {
    kind: Konventix.Agenda.Enums.ProgrammeEntryKind;
    cancelledReason: string | null;
};

export function cancelledNote(cell: CancellableCell): string {
    if (cell.kind === 'competition') {
        return 'Abgesagt';
    }

    return cell.cancelledReason
        ? `Fällt aus – ${cell.cancelledReason}`
        : 'Fällt aus';
}

/**
 * A key unique across both kinds: `itemId` holds a competition's ULID for
 * a block, and nothing promises the two id spaces never meet.
 */
export function cellKey(cell: {
    kind: Konventix.Agenda.Enums.ProgrammeEntryKind;
    itemId: string;
}): string {
    return `${cell.kind}:${cell.itemId}`;
}
