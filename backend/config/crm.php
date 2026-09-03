<?php

return [
    /*
     * Days without contact before an open opportunity is treated as inactive.
     * Overridable per organization via organizations.settings.
     */
    'inactivity_threshold_days' => env('CRM_INACTIVITY_THRESHOLD_DAYS', 7),

    'currency' => env('CRM_CURRENCY', 'MYR'),
];
