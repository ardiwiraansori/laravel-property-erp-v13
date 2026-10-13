<?php

return [
    // Deliberately hard-disabled by default. An MFA enrollment and challenge
    // workflow must be reviewed before enabling platform write operations.
    'mutations_enabled' => (bool) env('PLATFORM_MUTATIONS_ENABLED', false),
];
