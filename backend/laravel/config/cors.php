<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing
|--------------------------------------------------------------------------
|
| In development the Vite dev server proxies /api to this backend, so the
| browser sees one origin and CORS never applies. In production the frontend
| and the API sit on different hosts, which is when these settings take effect.
|
*/

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    /*
     | Explicit origins rather than '*'. A wildcard would let any site on the
     | internet call this API from a visitor's browser, and this API serves
     | applicant records covered by RA 10173.
     */
    'allowed_origins' => array_filter([
        env('FRONTEND_URL', 'http://localhost:5173'),
        'http://localhost:5173',
        'http://127.0.0.1:5173',
    ]),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 3600,

    /*
     | False because authentication uses Bearer tokens rather than cookies.
     | Enabling credentials would also forbid a wildcard origin, and we are not
     | relying on cookies at all.
     */
    'supports_credentials' => false,

];
