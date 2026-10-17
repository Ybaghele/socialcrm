import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/*
 * Reverb speaks the Pusher protocol, so Echo is bundled with pusher-js as the
 * transport. The VITE_REVERB_* values come from .env and must match the
 * REVERB_* values the `php artisan reverb:start` server is running with.
 */
window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
