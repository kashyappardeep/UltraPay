<?php

return [
    // OTP
    'otp_test_mode' => env('OTP_TEST_MODE', true),   // true = SMS nahi jaayega, OTP fixed
    'otp_test_code' => env('OTP_TEST_CODE', '123456'),
    'otp_expiry_minutes' => 5,
    'otp_resend_seconds' => 60,
    'otp_max_attempts' => 5,

    // SMS: log | fast2sms | msg91
    'sms_driver' => env('SMS_DRIVER', 'log'),
    'fast2sms_key' => env('FAST2SMS_API_KEY'),
    'msg91_key' => env('MSG91_AUTH_KEY'),
    'msg91_template_id' => env('MSG91_TEMPLATE_ID'),

    // Registration token (OTP verify ke baad naye user ko)
    'registration_token_minutes' => 15,

    // PIN
    'pin_length' => 4,
    'pin_max_attempts' => 5,
    'pin_lock_minutes' => 30,

    // A1Topup (Recharge) - sab lowercase hona chahiye, controller input lowercase karke match karta hai
    'a1topup_username' => env('A1TOPUP_USERNAME'),
    'a1topup_password'  => env('A1TOPUP_PASSWORD'),
    'a1topup_success_statuses' => ['success', 'successful', 'completed', 'done'],
    'a1topup_failure_statuses' => ['failed', 'failure', 'error', 'cancelled', 'canceled', 'reject', 'rejected', 'invalid'],

     // ANB deposit (EVM chain: BSC / Ethereum / Polygon)
    'anb' => [
        'network' => env('ANB_NETWORK', 'BNB Smart Chain (BEP-20)'),
                'chain_id' => (int) env('ANB_CHAIN_ID', 56),          // BSC = 56
        'rpc_url' => env('ANB_RPC_URL', 'https://bsc-rpc.publicnode.com'),
        'token_contract' => strtolower((string) env('ANB_TOKEN_CONTRACT', '0x385FC4e0520Eec19eF3B8df45f46C3Ec4a0Bf61a')),
        'company_address' => strtolower((string) env('ANB_COMPANY_ADDRESS', '0x01303dd0Ecfb4D7Efa4eb3E66Aa26dDd8F6cf556')),
        'decimals' => (int) env('ANB_DECIMALS', 18),
        'confirmations' => (int) env('ANB_CONFIRMATIONS', 15),
        'start_block' => env('ANB_START_BLOCK'),
        'block_chunk' => (int) env('ANB_BLOCK_CHUNK', 500),
        // App poll par turant check: kitne latest blocks scan karne hain
        'recent_scan_blocks' => (int) env('ANB_RECENT_SCAN_BLOCKS', 1000),
        'min_deposit' => (float) env('ANB_MIN_DEPOSIT', 1),
                'deposit_expiry_minutes' => (int) env('ANB_DEPOSIT_TIMER_MINUTES', 15), // app mein timer
        'match_window_hours' => 24,
    ],
];
