// src/utils/cache.js
'use strict';

/**
 * Simple in-memory TTL cache with:
 * - get/set with expiry
 * - "single-flight" request de-duping via wrap()
 *
 * Notes:
 * - This is per Node process. If you run multiple PM2 instances, each has its own cache.
 * - Keys should include user identity + endpoint + params to avoid leaking between users.
 */

class TTLCache {
  constructor({ defaultTtlMs = 30_000, maxItems = 2_000 } = {}) {
    this.defaultTtlMs = defaultTtlMs;
    this.maxItems = maxItems;

    /** @type {Map<string, { value:any, expiresAt:number }>} */
    this.store = new Map();

    /** @type {Map<string, Promise<any>>} */
    this.inFlight = new Map();
  }

  _now() {
    return Date.now();
  }

  _pruneIfNeeded() {
    if (this.store.size <= this.maxItems) return;

    // Basic prune: remove expired first, then oldest insertion order
    const now = this._now();

    for (const [k, v] of this.store.entries()) {
      if (v.expiresAt <= now) this.store.delete(k);
    }

    while (this.store.size > this.maxItems) {
      const oldestKey = this.store.keys().next().value;
      if (!oldestKey) break;
      this.store.delete(oldestKey);
    }
  }

  get(key) {
    const item = this.store.get(key);
    if (!item) return undefined;

    if (item.expiresAt <= this._now()) {
      this.store.delete(key);
      return undefined;
    }

    return item.value;
  }

  set(key, value, ttlMs = this.defaultTtlMs) {
    const expiresAt = this._now() + Math.max(0, ttlMs);
    this.store.set(key, { value, expiresAt });
    this._pruneIfNeeded();
  }

  del(key) {
    this.store.delete(key);
    this.inFlight.delete(key);
  }

  clear() {
    this.store.clear();
    this.inFlight.clear();
  }

  /**
   * wrap(key, ttlMs, fetcher) returns cached value if present,
   * otherwise runs fetcher once for concurrent callers, caches result, returns it.
   */
  async wrap(key, ttlMs, fetcher) {
    const cached = this.get(key);
    if (cached !== undefined) return cached;

    if (this.inFlight.has(key)) {
      return this.inFlight.get(key);
    }

    const p = (async () => {
      try {
        const value = await fetcher();
        this.set(key, value, ttlMs);
        return value;
      } finally {
        this.inFlight.delete(key);
      }
    })();

    this.inFlight.set(key, p);
    return p;
  }
}

module.exports = { TTLCache };
