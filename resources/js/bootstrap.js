import axios from 'axios';

// axios come client HTTP di default per eventuali chiamate dirette (oltre a Inertia).
// L'header identifica le richieste XHR lato Laravel; il token CSRF viaggia via cookie/meta.
window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
