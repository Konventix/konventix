/**
 * Web-push service worker (ADR-0041 point 7). Deliberately tiny and static:
 * it only turns the typed push payload — title, body, url, tag — into an OS
 * notification and opens the URL on click. It never caches, never fetches,
 * and stays apart from the entrance client's PWA worker.
 */
self.addEventListener('push', (event) => {
    if (!event.data) {
        return;
    }

    let payload;
    try {
        payload = event.data.json();
    } catch {
        return;
    }

    event.waitUntil(
        self.registration.showNotification(payload.title ?? 'Notification', {
            body: payload.body ?? '',
            tag: payload.tag ?? undefined,
            data: { url: payload.url ?? null },
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = event.notification.data && event.notification.data.url;

    if (url) {
        event.waitUntil(self.clients.openWindow(url));
    }
});
