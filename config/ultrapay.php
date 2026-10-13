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
];