<?php

return [
    'health' => [
        'max_queue_backlog' => (int) env('OPS_MAX_QUEUE_BACKLOG', 100),
        'max_failed_jobs' => (int) env('OPS_MAX_FAILED_JOBS', 0),
        'max_failed_outbox' => (int) env('OPS_MAX_FAILED_OUTBOX', 0),
        'max_outbox_pending_age_seconds' => (int) env('OPS_MAX_OUTBOX_PENDING_AGE_SECONDS', 900),
        'scheduler_stale_seconds' => (int) env('OPS_SCHEDULER_STALE_SECONDS', 180),
    ],
];
