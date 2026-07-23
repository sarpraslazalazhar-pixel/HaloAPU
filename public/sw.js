self.addEventListener('push', function (e) {
    if (!(self.Notification && self.Notification.permission === 'granted')) {
        return;
    }

    if (e.data) {
        var msg = e.data.json();
        e.waitUntil(self.registration.showNotification(msg.title, {
            body: msg.body,
            icon: msg.icon || '/images/logo.png', // Fallback icon
            actions: msg.actions || [],
            data: msg.data
        }));
    }
});

self.addEventListener('notificationclick', function (e) {
    e.notification.close();

    if (e.notification.data && e.notification.data.url) {
        e.waitUntil(clients.matchAll({
            type: 'window',
            includeUncontrolled: true
        }).then(function (windowClients) {
            // Check if there is already a window/tab open with the target URL
            for (var i = 0; i < windowClients.length; i++) {
                var client = windowClients[i];
                // If so, just focus it.
                if (client.url === e.notification.data.url && 'focus' in client) {
                    return client.focus();
                }
            }
            // If not, then open the target URL in a new window/tab.
            if (clients.openWindow) {
                return clients.openWindow(e.notification.data.url);
            }
        }));
    }
});
