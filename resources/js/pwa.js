/**
 * Panel PWA wiring: one service worker registration and the install button's state.
 *
 * A new build is picked up the usual way — the waiting worker activates once every panel
 * tab is closed. Nothing prompts the user to reload mid-session.
 *
 * The worker is registered here rather than by vite-plugin-pwa's auto-register, because
 * Firebase needs its project config on the registration URL and only one worker may own
 * the scope.
 */
let registration = null;
let registering = null;
let installPrompt = null;

/**
 * Register (once) and resolve when a worker is ACTIVE — getToken() needs an active
 * worker, an installing one has no pushManager yet.
 */
export function registerPanelServiceWorker(firebaseConfig = null) {
    // Disabled temporarily: public/sw.js is not deployed yet.
    return Promise.resolve(undefined);
}

/** Chromium fires this instead of showing its own install UI; we show ours. */
export function initInstallPrompt() {
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        installPrompt = e;
        window.dispatchEvent(new Event('pwa:installable'));
    });
    window.addEventListener('appinstalled', () => {
        installPrompt = null;
        window.dispatchEvent(new Event('pwa:installed'));
    });
}

export function canInstall() {
    return !!installPrompt;
}

export async function promptInstall() {
    if (!installPrompt) return false;
    installPrompt.prompt();
    const { outcome } = await installPrompt.userChoice;
    installPrompt = null;
    window.dispatchEvent(new Event('pwa:installed'));
    return outcome === 'accepted';
}
