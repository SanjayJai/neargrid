export interface ApiMeta {
  request_id: string | null;
  api_version: string;
  timestamp: string;
}

export interface ApiSuccessEnvelope<T> {
  status: 'success';
  data: T;
  meta: ApiMeta;
}

export interface ApiErrorEnvelope {
  status: 'error';
  error: {
    code: string;
    message: string;
    details?: Record<string, unknown>;
  };
  meta: ApiMeta;
}

export type ApiEnvelope<T> = ApiSuccessEnvelope<T> | ApiErrorEnvelope;

export interface UserDto {
  public_id: string;
  name: string | null;
  email: string | null;
  phone_e164: string | null;
  phone_verified_at: string | null;
  avatar_url: string | null;
  roles: string[];
  permissions: string[];
  created_at: string | null;
}

export interface UserDeviceDto {
  public_id: string;
  platform: 'web' | 'android' | 'ios';
  device_identifier: string;
  has_push_token: boolean;
  app_version: string | null;
  last_active_at: string | null;
  created_at: string | null;
}

export interface SessionTokenDto {
  session_id: string;
  name: string;
  device_public_id: string | null;
  abilities: string[];
  last_used_at: string | null;
  expires_at: string | null;
  created_at: string | null;
  is_current: boolean;
}

export interface OtpChallengeResponseDto {
  challenge_id: string;
  expires_in_seconds: number;
  resend_after_seconds: number;
}

/**
 * Server-only shape returned by Laravel POST /api/v1/auth/otp/verify.
 * NEVER serialize `access_token` into browser responses or RSC props.
 */
export interface LaravelVerifyOtpServerData {
  token_type: 'Bearer';
  access_token: string;
  session_id: string;
  expires_at: string;
  user: UserDto;
  device?: UserDeviceDto;
}

/**
 * Safe browser-facing payload returned by Next.js BFF after OTP verification.
 */
export interface BffAuthClientData {
  authenticated: true;
  session_id: string;
  expires_at: string;
  user: UserDto;
  device?: UserDeviceDto;
}
