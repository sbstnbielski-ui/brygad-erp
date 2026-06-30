/**
 * BRYGAD ERP - Service Worker for Web Push Notifications
 * Version: 1.0
 */

const CACHE_NAME = 'brygad-v1';

// Install event - cache basic assets
self.addEventListener('install', (event) => {
    console.log('[SW] Installing Service Worker...');
    self.skipWaiting();
});

// Activate event
self.addEventListener('activate', (event) => {
    console.log('[SW] Service Worker activated');
    event.waitUntil(clients.claim());
});

// Push event - receive and display notification
self.addEventListener('push', (event) => {
    console.log('[SW] Push received:', event);
    
    let data = {
        title: 'BRYGAD ERP',
        body: 'Nowe powiadomienie',
        icon: '/assets/logo-brygad-erp.png',
        badge: '/assets/logo-brygad-erp.png',
        tag: 'brygad-notification',
        data: {}
    };
    
    if (event.data) {
        try {
            const payload = event.data.json();
            data = {
                ...data,
                ...payload
            };
        } catch (e) {
            data.body = event.data.text();
        }
    }
    
    const options = {
        body: data.body,
        icon: data.icon || '/assets/logo-brygad-erp.png',
        badge: data.badge || '/assets/logo-brygad-erp.png',
        tag: data.tag || 'brygad-notification',
        data: data.data || {},
        vibrate: [200, 100, 200],
        requireInteraction: data.requireInteraction || false,
        actions: data.actions || []
    };
    
    event.waitUntil(
        self.registration.showNotification(data.title, options)
    );
});

// Notification click event - open relevant page
self.addEventListener('notificationclick', (event) => {
    console.log('[SW] Notification clicked:', event);
    
    event.notification.close();
    
    let url = '/dashboard.php';
    
    // Check notification data for specific URL
    if (event.notification.data && event.notification.data.url) {
        url = event.notification.data.url;
    }
    
    // Handle action buttons
    if (event.action) {
        switch (event.action) {
            case 'view':
                // Open specific task/item
                if (event.notification.data && event.notification.data.task_id) {
                    url = '/zadania/show_mobile.php?id=' + event.notification.data.task_id;
                }
                break;
            case 'dismiss':
                // Just close, already done above
                return;
        }
    }
    
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then((windowClients) => {
                // Check if there's already a window/tab open
                for (let client of windowClients) {
                    if (client.url.includes(self.registration.scope) && 'focus' in client) {
                        client.navigate(url);
                        return client.focus();
                    }
                }
                // Otherwise open new window
                if (clients.openWindow) {
                    return clients.openWindow(url);
                }
            })
    );
});

// Handle push subscription change
self.addEventListener('pushsubscriptionchange', (event) => {
    console.log('[SW] Push subscription changed');
    
    event.waitUntil(
        self.registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(self.VAPID_PUBLIC_KEY)
        })
        .then((subscription) => {
            // Re-register subscription with server
            return fetch('/api/push/subscribe.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(subscription)
            });
        })
    );
});

// Utility function to convert VAPID key
function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding)
        .replace(/-/g, '+')
        .replace(/_/g, '/');
    
    const rawData = atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    
    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}


