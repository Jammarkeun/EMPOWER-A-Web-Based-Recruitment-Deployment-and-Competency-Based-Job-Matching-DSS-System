<?php

/*
|--------------------------------------------------------------------------
| OCR Service
|--------------------------------------------------------------------------
|
| Settings for the FastAPI document-reading service in backend/ocr_service.
|
| The service is optional. When it is unreachable the application carries on
| exactly as before, with HR typing details in by hand — document reading is a
| convenience that saves retyping, never a dependency the recruitment process
| rests on.
|
*/

return [

    'url' => env('OCR_SERVICE_URL', 'http://127.0.0.1:8001'),

    /*
     | Shared secret sent as the X-OCR-Token header. Must match OCR_SERVICE_TOKEN
     | in the Python service's environment. Leave both unset only when the
     | service is not reachable from outside the host.
     */
    'token' => env('OCR_SERVICE_TOKEN'),

    /*
     | Generous because recognition genuinely is slow: oneDNN has to stay
     | disabled for PaddlePaddle 3.x to work at all, and the reference CPU
     | kernels take roughly forty seconds a page on a development laptop. The
     | first request after startup adds a few seconds more to load the models.
     |
     | OcrService::scan() raises PHP's own max_execution_time to match this
     | value. The two must agree — PHP's default of 30 seconds on the built-in
     | server was killing scans with a fatal error while the HTTP client sat
     | waiting out its 120.
     */
    'timeout' => (int) env('OCR_SERVICE_TIMEOUT', 120),

    'enabled' => (bool) env('OCR_SERVICE_ENABLED', true),

    /*
     | Fields below this confidence are flagged in the review screen so HR looks
     | at them properly rather than accepting the whole form at a glance.
     |
     | Set at 0.7 because names and addresses — the values extraction is worst at
     | — score below it, while pattern-matched values such as an email address or
     | a mobile number score well above.
     */
    'review_threshold' => (float) env('OCR_REVIEW_THRESHOLD', 0.7),

    /*
     | Applicant columns that a scan is permitted to fill. Anything the service
     | proposes outside this list is discarded before it reaches the client, so a
     | change to the Python extractor can never start writing to unexpected
     | columns.
     */
    'assignable_fields' => [
        'first_name',
        'middle_name',
        'last_name',
        'sex',
        'birth_date',
        'civil_status',
        'contact_number',
        'email',
        'present_address',
    ],
];
