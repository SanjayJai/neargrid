<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Configurable Data Retention Policies
    |--------------------------------------------------------------------------
    |
    | Retention durations are driven by environment configuration to support
    | compliance with India DPDP Act 2023 and GDPR data minimization rules.
    |
    */

    'consent_version' => env('PRIVACY_CONSENT_VERSION', 'v1.0'),

    'retention' => [
        'otp_hours' => (int) env('PRIVACY_OTP_RETENTION_HOURS', 24),
        'enquiry_pii_days' => (int) env('PRIVACY_ENQUIRY_PII_RETENTION_DAYS', 90),
        'contact_log_days' => (int) env('PRIVACY_CONTACT_LOG_RETENTION_DAYS', 180),
        'search_log_days' => (int) env('PRIVACY_SEARCH_LOG_RETENTION_DAYS', 90),
        'kyc_years_after_closure' => (int) env('PRIVACY_KYC_RETENTION_YEARS', 5),
        'tax_record_years' => (int) env('PRIVACY_TAX_RECORD_RETENTION_YEARS', 8),
        'audit_log_years' => (int) env('PRIVACY_AUDIT_LOG_RETENTION_YEARS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Legal & Statutory Retention Exceptions
    |--------------------------------------------------------------------------
    |
    | Records subject to statutory obligations (e.g., GST Section 36 tax records,
    | active fraud/legal holds, and security forensics) are exempt from routine
    | anonymization or immediate account-erasure deletion.
    |
    */

    'legal_exceptions' => [
        'gst_tax_invoices' => [
            'exempt_from_user_erasure' => true,
            'statute' => 'Indian CGST/SGST Act Section 36 & Income Tax Act',
            'min_years' => (int) env('PRIVACY_TAX_RECORD_RETENTION_YEARS', 8),
        ],
        'active_legal_or_fraud_hold' => [
            'exempt_from_automated_purge' => true,
            'flag_column' => 'legal_hold',
        ],
        'security_audit_logs' => [
            'append_only' => true,
            'min_years' => (int) env('PRIVACY_AUDIT_LOG_RETENTION_YEARS', 5),
        ],
    ],

];
