import { useEffect, useRef } from 'react';
import { router } from '@inertiajs/react';

export function useIdleTimer(logoutUrl: string, timeout = 30 * 60 * 1000) { // Default 30 mins
 const timeoutRef = useRef<NodeJS.Timeout | null>(null);

 const resetTimer = () => {
 if (timeoutRef.current) {
 clearTimeout(timeoutRef.current);
 }
 
 timeoutRef.current = setTimeout(() => {
 // Log out user
 router.post(logoutUrl);
 }, timeout);
 };

 useEffect(() => {
 const events = ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart'];
 
 const handleEvent = () => resetTimer();

 // Initial setup
 resetTimer();

 events.forEach((event) => {
 document.addEventListener(event, handleEvent);
 });

 return () => {
 if (timeoutRef.current) clearTimeout(timeoutRef.current);
 events.forEach((event) => {
 document.removeEventListener(event, handleEvent);
 });
 };
 }, [logoutUrl, timeout]);
}
