import { describe, expect, it } from 'vitest';
import { livePillFor } from '@/lib/livePill';

const admitted: Konventix.Platform.Data.ActiveEventData = {
    eventId: 'evt-1',
    eventSlug: 'kon-fest',
    eventName: 'Kon Fest',
    admissionId: 'adm-1',
    dayLabel: null,
    admittedAt: '2026-09-22T09:00:00+00:00',
    windowEndsAt: null,
    onSite: true,
};

describe('livePillFor', () => {
    it('renders nothing for a viewer who is not at an event', () => {
        expect(livePillFor(null)).toBeNull();
    });

    it('names the event and points at the live hub for an admitted member', () => {
        expect(livePillFor(admitted)).toEqual({
            label: 'Kon Fest',
            href: '/live',
            onSite: true,
        });
    });

    it('falls back to "Live" when the event has no name', () => {
        expect(livePillFor({ ...admitted, eventName: null })?.label).toBe(
            'Live',
        );
    });

    it('carries the off-site state through for the dot colour', () => {
        expect(livePillFor({ ...admitted, onSite: false })?.onSite).toBe(false);
    });
});
