/**
 * NearGrid - Safe Internal Navigation Validator
 *
 * Enforces strict validation of navigation destinations before invoking Next.js router.push().
 * Prevents Open Redirect (CWE-601), Script Injection (CWE-79), and malformed URL attacks.
 */

/**
 * Validates whether an input URL is a safe, internal, relative application route.
 *
 * Rules:
 * - Must be a non-empty string.
 * - Must start with exactly one leading slash ('/'), not protocol-relative ('//') or backslash ('/\').
 * - Must not contain any backslash characters ('\') anywhere in the path.
 * - Must not contain control characters (ASCII 0-31 or 127).
 * - Must not contain any absolute URI scheme in the path portion (e.g., 'javascript:', 'data:', 'vbscript:', 'http:', 'https:', 'file:').
 * - Must pass strict URL parsing against a canonical same-origin base ('http://localhost').
 * - Preserves valid internal query parameters (?key=val) and hash fragments (#section).
 */
export function isSafeInternalNavigationUrl(url: unknown): url is string {
  if (typeof url !== 'string') {
    return false;
  }

  const trimmed = url.trim();
  if (!trimmed || !trimmed.startsWith('/')) {
    return false;
  }

  // Reject protocol-relative '//', backslashes '/\' or any backslash in the entire string
  if (trimmed.startsWith('//') || trimmed.includes('\\')) {
    return false;
  }

  // Reject ASCII control characters (0-31, 127)
  // eslint-disable-next-line no-control-regex
  if (/[\x00-\x1F\x7F]/.test(trimmed)) {
    return false;
  }

  // Reject dangerous schemes in path segment (before query string and hash)
  const pathBeforeQueryOrHash = trimmed.split('?')[0].split('#')[0];
  if (pathBeforeQueryOrHash.includes(':')) {
    return false;
  }

  try {
    // Robust URL parsing with fixed dummy base
    const dummyBase = 'http://localhost';
    const parsed = new URL(trimmed, dummyBase);

    // Origin must strictly match the dummy origin
    if (parsed.origin !== dummyBase) {
      return false;
    }

    // Protocol must strictly be the dummyBase protocol ('http:')
    if (parsed.protocol !== 'http:') {
      return false;
    }

    // Host and hostname must be localhost (no host spoofing)
    if (parsed.hostname !== 'localhost') {
      return false;
    }

    // Pathname must begin with single '/' and never '//'
    if (!parsed.pathname.startsWith('/') || parsed.pathname.startsWith('//')) {
      return false;
    }

    return true;
  } catch {
    return false;
  }
}
