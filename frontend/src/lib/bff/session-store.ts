import crypto from 'crypto';
import Redis from 'ioredis';

export interface BffSessionRecord {
  accessToken: string;
  sessionId: string;
  userPublicId: string;
  expiresAt: string;
  createdAt: string;
}

export interface BffSessionStore {
  get(opaqueSessionId: string): Promise<BffSessionRecord | null>;
  set(opaqueSessionId: string, record: BffSessionRecord, ttlSeconds: number): Promise<void>;
  delete(opaqueSessionId: string): Promise<void>;
}

const KEY_PREFIX = 'neargrid:bff:sess:';

export function generateOpaqueSessionId(): string {
  return crypto.randomBytes(32).toString('base64url');
}

function deriveEncryptionKey(secret?: string): Buffer {
  const raw = secret ?? process.env.BFF_SESSION_SECRET ?? '';
  if (process.env.NODE_ENV === 'production' && raw.length < 32) {
    throw new Error('BFF_SESSION_SECRET must be configured with at least 32 characters in production.');
  }

  const effective = raw.length >= 16 ? raw : 'dev-only-insecure-bff-session-key-change-me';
  return crypto.createHash('sha256').update(effective).digest();
}

export function encryptSessionRecord(record: BffSessionRecord, secret?: string): string {
  const key = deriveEncryptionKey(secret);
  const iv = crypto.randomBytes(12);
  const cipher = crypto.createCipheriv('aes-256-gcm', key, iv);
  const plaintext = Buffer.from(JSON.stringify(record), 'utf8');
  const encrypted = Buffer.concat([cipher.update(plaintext), cipher.final()]);
  const tag = cipher.getAuthTag();

  return `${iv.toString('base64url')}.${tag.toString('base64url')}.${encrypted.toString('base64url')}`;
}

export function decryptSessionRecord(ciphertext: string, secret?: string): BffSessionRecord | null {
  const parts = ciphertext.split('.');
  if (parts.length !== 3) {
    return null;
  }

  try {
    const key = deriveEncryptionKey(secret);
    const iv = Buffer.from(parts[0], 'base64url');
    const tag = Buffer.from(parts[1], 'base64url');
    const encrypted = Buffer.from(parts[2], 'base64url');
    const decipher = crypto.createDecipheriv('aes-256-gcm', key, iv);
    decipher.setAuthTag(tag);
    const decrypted = Buffer.concat([decipher.update(encrypted), decipher.final()]).toString('utf8');
    const parsed = JSON.parse(decrypted) as BffSessionRecord;

    if (!parsed.accessToken || !parsed.expiresAt) {
      return null;
    }

    if (Date.parse(parsed.expiresAt) <= Date.now()) {
      return null;
    }

    return parsed;
  } catch {
    return null;
  }
}

export class MemoryBffSessionStore implements BffSessionStore {
  private readonly store = new Map<string, { payload: string; expiresAtMs: number }>();

  async get(opaqueSessionId: string): Promise<BffSessionRecord | null> {
    const entry = this.store.get(opaqueSessionId);
    if (!entry) {
      return null;
    }

    if (entry.expiresAtMs <= Date.now()) {
      this.store.delete(opaqueSessionId);
      return null;
    }

    const record = decryptSessionRecord(entry.payload);
    if (!record) {
      this.store.delete(opaqueSessionId);
      return null;
    }

    return record;
  }

  async set(opaqueSessionId: string, record: BffSessionRecord, ttlSeconds: number): Promise<void> {
    const payload = encryptSessionRecord(record);
    this.store.set(opaqueSessionId, {
      payload,
      expiresAtMs: Date.now() + Math.max(1, ttlSeconds) * 1000,
    });
  }

  async delete(opaqueSessionId: string): Promise<void> {
    this.store.delete(opaqueSessionId);
  }
}

export class RedisBffSessionStore implements BffSessionStore {
  private readonly client: Redis;

  constructor(redisUrl: string) {
    this.client = new Redis(redisUrl, {
      lazyConnect: true,
      maxRetriesPerRequest: 2,
      enableOfflineQueue: false,
    });
  }

  async get(opaqueSessionId: string): Promise<BffSessionRecord | null> {
    const raw = await this.client.get(`${KEY_PREFIX}${opaqueSessionId}`);
    if (!raw) {
      return null;
    }

    const record = decryptSessionRecord(raw);
    if (!record) {
      await this.delete(opaqueSessionId);
      return null;
    }

    return record;
  }

  async set(opaqueSessionId: string, record: BffSessionRecord, ttlSeconds: number): Promise<void> {
    const encrypted = encryptSessionRecord(record);
    await this.client.set(`${KEY_PREFIX}${opaqueSessionId}`, encrypted, 'EX', Math.max(1, ttlSeconds));
  }

  async delete(opaqueSessionId: string): Promise<void> {
    await this.client.del(`${KEY_PREFIX}${opaqueSessionId}`);
  }
}

const globalForStore = globalThis as unknown as {
  __neargridBffSessionStore?: BffSessionStore;
};

export function getBffSessionStore(): BffSessionStore {
  if (globalForStore.__neargridBffSessionStore) {
    return globalForStore.__neargridBffSessionStore;
  }

  const redisUrl = process.env.REDIS_URL?.trim();

  if (redisUrl) {
    globalForStore.__neargridBffSessionStore = new RedisBffSessionStore(redisUrl);
    return globalForStore.__neargridBffSessionStore;
  }

  if (process.env.NODE_ENV === 'production') {
    throw new Error('REDIS_URL must be configured in production for the BFF session store.');
  }

  globalForStore.__neargridBffSessionStore = new MemoryBffSessionStore();
  return globalForStore.__neargridBffSessionStore;
}
