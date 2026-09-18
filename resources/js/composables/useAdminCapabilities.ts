import { usePage } from '@inertiajs/vue3';
import { computed, type ComputedRef } from 'vue';

/**
 * The console capabilities the shell shares (`HandleInertiaRequests`,
 * `admin.*`). One reader for them, so a page never casts `page.props.admin`
 * by hand. Absent off the admin host, where every answer is false.
 */
export function useAdminCapabilities(): {
    canManageMedia: ComputedRef<boolean>;
} {
    const page = usePage();

    const admin = computed(
        () => (page.props.admin as { canManageMedia?: boolean } | null) ?? null,
    );

    return {
        canManageMedia: computed(() => admin.value?.canManageMedia === true),
    };
}
