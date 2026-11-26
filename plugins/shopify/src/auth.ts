import { createHmac, timingSafeEqual } from "node:crypto";
import { shopifyId } from "./config.ts";

function equals(actual: string, expected: string): boolean {
  const left = Buffer.from(actual);
  const right = Buffer.from(expected);
  return left.length === right.length && timingSafeEqual(left, right);
}

export function webhookValid(raw: Buffer, signature: string, secret: string): boolean {
  return equals(signature, createHmac("sha256", secret).update(raw).digest("base64"));
}

export function proxyIdentity(query: URLSearchParams, secret: string, shop: string, now = Date.now()): { customer: string; prefix: string } | null {
  const fields = ["signature", "shop", "timestamp", "logged_in_customer_id", "path_prefix"];
  if (fields.some((field) => query.getAll(field).length !== 1) || query.get("shop") !== shop) return null;
  const timestamp = query.get("timestamp") ?? "";
  const prefix = query.get("path_prefix") ?? "";
  if (!/^\d{10}$/.test(timestamp) || Math.abs(now - Number(timestamp) * 1000) > 5 * 60_000
    || !/^\/(a|apps|community|tools)\/[a-z0-9_-]+$/.test(prefix)) return null;
  const values = new URLSearchParams(query);
  values.delete("signature");
  const canonical = [...new Set(values.keys())].map((key) => key + "=" + values.getAll(key).join(",")).sort().join("");
  if (!equals(query.get("signature") ?? "", createHmac("sha256", secret).update(canonical).digest("hex"))) return null;
  try {
    return { customer: shopifyId("Customer", query.get("logged_in_customer_id") ?? ""), prefix };
  } catch {
    return null;
  }
}

export function csrf(secret: string, customer: string, slug: string, now = Date.now()): string {
  const bucket = Math.floor(now / 600_000);
  return createHmac("sha256", secret).update(JSON.stringify(["blocksign-purchase", customer, slug, bucket])).digest("hex");
}

export function csrfValid(value: string, secret: string, customer: string, slug: string, now = Date.now()): boolean {
  return equals(value, csrf(secret, customer, slug, now)) || equals(value, csrf(secret, customer, slug, now - 600_000));
}
