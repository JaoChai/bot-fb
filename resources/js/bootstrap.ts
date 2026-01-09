import axios from 'axios';

// Set up axios defaults
window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true;

// CSRF token is automatically handled by Laravel Inertia
// No need to manually set it - Inertia handles CSRF protection

// Type declarations for window
declare global {
  interface Window {
    axios: typeof axios;
  }
}
