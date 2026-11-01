<?php

return [
    'issuer' => env('IAM_ISSUER', env('IAM_BASE_URL', 'http://iam-sso-core.test:8888')),
    'client_id' => env('IAM_CLIENT_ID', 'app_mochi_finance_admin'),
    'redirect_uri' => env('IAM_REDIRECT_URI', 'http://mochi-finance-admin.test:8888/auth/callback'),
    'post_logout_uri' => env('IAM_POST_LOGOUT_URI', 'http://mochi-finance-admin.test:8888/login'),
    'pwa_url' => env('IAM_PWA_URL', 'http://localhost:3001'),
    'webhook_secret' => env('IAM_WEBHOOK_SECRET', ''),
];
