import { useEffect } from 'react';
import axios from 'axios';

function urlBase64ToUint8Array(base64String: string) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding)
        .replace(/\-/g, '+')
        .replace(/_/g, '/');

    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);

    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}

export function useWebPush(user: any) {
    useEffect(() => {
        if (!user) return;

        const subscribe = async () => {
            if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
                return;
            }

            try {
                const registration = await navigator.serviceWorker.register('/sw.js');
                const permission = await Notification.requestPermission();
                
                if (permission !== 'granted') {
                    return;
                }

                const existingSubscription = await registration.pushManager.getSubscription();
                if (existingSubscription) {
                    // Already subscribed
                    await axios.post('/push/subscribe', existingSubscription);
                    return;
                }

                const vapidPublicKey = import.meta.env.VITE_VAPID_PUBLIC_KEY;
                if (!vapidPublicKey) {
                    console.error('VITE_VAPID_PUBLIC_KEY is missing');
                    return;
                }

                const convertedVapidKey = urlBase64ToUint8Array(vapidPublicKey);

                const subscription = await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: convertedVapidKey
                });

                await axios.post('/push/subscribe', subscription);
            } catch (error) {
                console.error('Failed to subscribe to Web Push:', error);
            }
        };

        if ('Notification' in window && Notification.permission === 'granted') {
            subscribe();
        } else {
            // Subscribe logic can be called explicitly when user grants permission.
            // But we can also attempt it here, and if permission is default, it might wait or we only do it if they accepted.
            // In our layouts, we prompt for permission. So we can export subscribe function.
        }
    }, [user]);

    return {
        subscribe: async () => {
            if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
                return false;
            }
            try {
                const registration = await navigator.serviceWorker.register('/sw.js');
                const vapidPublicKey = import.meta.env.VITE_VAPID_PUBLIC_KEY;
                const convertedVapidKey = urlBase64ToUint8Array(vapidPublicKey);
                
                let subscription = await registration.pushManager.getSubscription();
                if (!subscription) {
                    subscription = await registration.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: convertedVapidKey
                    });
                }
                await axios.post('/push/subscribe', subscription);
                return true;
            } catch (e) {
                console.error(e);
                return false;
            }
        }
    };
}
