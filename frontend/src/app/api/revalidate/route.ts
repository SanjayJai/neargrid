import { NextResponse } from 'next/server';
import { revalidatePath } from 'next/cache';
import crypto from 'node:crypto';

export async function POST(request: Request): Promise<NextResponse> {
  const signatureHeader =
    request.headers.get('x-webhook-signature') ||
    request.headers.get('X-Webhook-Signature');

  if (!signatureHeader) {
    return NextResponse.json(
      { error: 'Missing webhook signature header' },
      { status: 401 }
    );
  }

  // Parse t={timestamp},v1={hash}
  const parts = signatureHeader.split(',');
  let timestampStr: string | null = null;
  let signatureHex: string | null = null;

  for (const part of parts) {
    const [k, v] = part.trim().split('=');
    if (k === 't') timestampStr = v;
    if (k === 'v1') signatureHex = v;
  }

  if (!timestampStr || !signatureHex) {
    return NextResponse.json(
      { error: 'Malformed webhook signature header format' },
      { status: 401 }
    );
  }

  const timestamp = parseInt(timestampStr, 10);
  if (Number.isNaN(timestamp)) {
    return NextResponse.json(
      { error: 'Invalid signature timestamp' },
      { status: 400 }
    );
  }

  // Replay protection: max 60 seconds clock skew
  const now = Math.floor(Date.now() / 1000);
  if (Math.abs(now - timestamp) > 60) {
    return NextResponse.json(
      { error: 'Signature timestamp expired or outside acceptable 60s window' },
      { status: 400 }
    );
  }

  const rawBody = await request.text();
  const secret =
    process.env.REVALIDATION_WEBHOOK_SECRET ||
    process.env.INTERNAL_WEBHOOK_SECRET ||
    (process.env.NODE_ENV !== 'production'
      ? 'neargrid-local-revalidation-webhook-secret-key-32b'
      : '');

  if (!secret) {
    console.error('REVALIDATION_WEBHOOK_SECRET is not configured.');
    return NextResponse.json(
      { error: 'Revalidation webhook secret is not configured on server' },
      { status: 500 }
    );
  }

  const expectedSignature = crypto
    .createHmac('sha256', secret)
    .update(`${timestamp}.${rawBody}`)
    .digest('hex');

  const sigBuffer = Buffer.from(signatureHex, 'hex');
  const expectedBuffer = Buffer.from(expectedSignature, 'hex');

  if (
    sigBuffer.length !== expectedBuffer.length ||
    !crypto.timingSafeEqual(sigBuffer, expectedBuffer)
  ) {
    return NextResponse.json(
      { error: 'Invalid HMAC signature' },
      { status: 401 }
    );
  }

  let body: { paths?: unknown };
  try {
    body = JSON.parse(rawBody);
  } catch {
    return NextResponse.json(
      { error: 'Malformed JSON payload' },
      { status: 400 }
    );
  }

  if (!body || !Array.isArray(body.paths) || body.paths.length === 0) {
    return NextResponse.json(
      { error: 'Payload must include a non-empty array of paths' },
      { status: 400 }
    );
  }

  const revalidated: string[] = [];
  for (const p of body.paths) {
    if (typeof p === 'string' && p.startsWith('/')) {
      try {
        revalidatePath(p);
        revalidated.push(p);
      } catch (err) {
        console.warn(`Failed to revalidate path: ${p}`, err);
      }
    }
  }

  return NextResponse.json({
    revalidated: true,
    paths: revalidated,
    timestamp,
  });
}
