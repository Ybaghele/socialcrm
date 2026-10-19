<?php

return [
    'trial_days' => (int) env('OMNIFLOW_TRIAL_DAYS', 14),
    'trial_plan' => env('OMNIFLOW_TRIAL_PLAN', 'growth'),

    // Slugs a customer may not claim (they'd collide with routes or look official).
    'reserved_slugs' => ['www', 'app', 'api', 'admin', 'billing', 'login', 'register', 'webhooks', 'support', 'status', 'omniflow'],

    // Created for every new workspace. Matches your UI pipeline.
    'default_pipeline_stages' => [
        ['name' => 'New Inquiry',      'color' => '#64748b', 'is_default' => true,  'is_won' => false],
        ['name' => 'Pricing Inquiry',  'color' => '#0ea5e9', 'is_default' => false, 'is_won' => false],
        ['name' => 'Qualified',        'color' => '#6366f1', 'is_default' => false, 'is_won' => false],
        ['name' => 'Hot Lead',         'color' => '#f59e0b', 'is_default' => false, 'is_won' => false],
        ['name' => 'Won / Customer',   'color' => '#10b981', 'is_default' => false, 'is_won' => true],
    ],

    // ONE platform-level Meta app serves every tenant. Tenants connect their own
    // WhatsApp/IG/Page assets to it (Embedded Signup / OAuth), so the webhook is global.
    'meta' => [
        'app_secret' => env('META_APP_SECRET'),
        'verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),

        // Check Meta's current Graph API version and set META_GRAPH_VERSION; old versions get retired.
        'graph_version' => env('META_GRAPH_VERSION', 'v22.0'),

        // If you onboard Instagram via "Instagram Login" (instagram_business_* permissions)
        // rather than Facebook Login, set META_INSTAGRAM_HOST=https://graph.instagram.com
        'hosts' => [
            'whatsapp' => 'https://graph.facebook.com',
            'messenger' => 'https://graph.facebook.com',
            'instagram' => env('META_INSTAGRAM_HOST', 'https://graph.facebook.com'),
        ],

        // Self-service connect (WhatsApp Embedded Signup). Off until all three are
        // set, which needs Business Verification + App Review first; while blank
        // the Channels screen offers the manual paste-a-token form only.
        'app_id' => env('META_APP_ID'),
        // Registered in the Meta app's "Valid OAuth Redirect URIs". Defaults to
        // the callback route below; set it explicitly for production.
        'redirect_uri' => env('META_REDIRECT_URI'),
        // From the WhatsApp > Embedded Signup configuration in the Meta app.
        'embedded_signup_config_id' => env('META_EMBEDDED_SIGNUP_CONFIG_ID'),
    ],

    // What one WhatsApp template message costs the tenant's wallet, in paise.
    // PLACEHOLDERS (marketing matches the ~₹0.88 on your billing screen): set from Meta's India rate card + your margin.
    // Free-form replies inside the 24h window are not charged.
    'pricing' => [
        'whatsapp_template_paise' => ['marketing' => 88, 'utility' => 30, 'authentication' => 30],
    ],

    // Broadcasts ride the per-minute scheduler, so each tick does a bounded slice
    // of work: enrol a chunk, then queue a chunk of sends. The hold parks an
    // enrolment for a few minutes so a slow worker is not double-queued.
    'campaigns' => [
        'enroll_batch' => (int) env('OMNIFLOW_CAMPAIGN_ENROLL_BATCH', 500),
        'send_batch' => (int) env('OMNIFLOW_CAMPAIGN_SEND_BATCH', 50),
        'retry_after_minutes' => (int) env('OMNIFLOW_CAMPAIGN_HOLD_MINUTES', 15),
    ],

    'max_text_length' => ['whatsapp' => 4096, 'instagram' => 1000, 'messenger' => 1000], // IG/Messenger values are conservative
];
