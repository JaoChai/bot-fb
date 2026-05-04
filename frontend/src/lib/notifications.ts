const BASE_TITLE = 'BotJao';
let audioContext: AudioContext | null = null;

export async function requestNotificationPermission(): Promise<NotificationPermission> {
  if (!('Notification' in window)) return 'denied';
  if (Notification.permission !== 'default') return Notification.permission;
  return Notification.requestPermission();
}

export function showBrowserNotification(title: string, options?: NotificationOptions): void {
  if (!('Notification' in window) || Notification.permission !== 'granted') return;
  try { new Notification(title, { icon: '/favicon.ico', ...options }); } catch { /* silent */ }
}

export function playPing(): void {
  try {
    if (!audioContext) audioContext = new AudioContext();
    const osc = audioContext.createOscillator();
    const gain = audioContext.createGain();
    osc.connect(gain);
    gain.connect(audioContext.destination);
    osc.frequency.value = 800;
    osc.type = 'sine';
    gain.gain.value = 0.1;
    gain.gain.exponentialRampToValueAtTime(0.001, audioContext.currentTime + 0.3);
    osc.start(audioContext.currentTime);
    osc.stop(audioContext.currentTime + 0.3);
  } catch { /* silent */ }
}

export function setUnreadBadge(count: number): void {
  document.title = count > 0 ? `(${count}) ${BASE_TITLE}` : BASE_TITLE;
}
