<?php

return [

    /*
    | How many days of page views (visits, with their events) are kept. Older
    | ones are deleted every night by analytics:prune (routes/console.php). The
    | longest period the admin shows is 90 days (Analytics), and the dashboard
    | compares 30 days with the 30 before, so 90 keeps every view complete.
    | Backups keep deleted rows a little longer (database.backups.keep days).
    */
    'retention_days' => (int) env('ANALYTICS_RETENTION_DAYS', 90),

];
