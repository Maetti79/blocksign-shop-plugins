import { realpathSync, readFileSync, statSync } from "node:fs";
import { dirname, isAbsolute, join, resolve } from "node:path";
import { placement, sha256 } from "../../../packages/typescript/src/blocksign.ts";
import type { Document, Placement } from "../../../packages/typescript/src/blocksign.ts";

export interface Rule {
  variant?: string;
  product?: string;
  tag?: string;
  flow: "after_payment" | "before_payment";
  mode: "download" | "shipment" | "membership";
  documents: { file: string; placement?: Partial<Placement> }[];
  download?: string;
  slug?: string;
}
export interface Config {
  shop: string;
  appSecret: string;
  appId: string;
  blocksignKey: string;
  db: string;
  files: string;
  archive?: string;
  rules: Rule[];
  port: number;
}

export function shopifyId(type: "Order" | "Customer" | "Product" | "ProductVariant", id: string | number): string {
  const value = typeof id === "number" && Number.isSafeInteger(id) ? String(id) : id;
  if (typeof value !== "string") throw new Error("Invalid Shopify resource identifier.");
  const prefix = "gid://shopify/" + type + "/";
  const digits = value.startsWith(prefix) ? value.slice(prefix.length) : value;
  if (!/^[1-9][0-9]{0,19}$/.test(digits)) throw new Error("Invalid Shopify resource identifier.");
  return prefix + digits;
}

export function filename(value: string): string {
  if (typeof value !== "string" || !/^[a-z0-9][a-z0-9._-]{0,119}\.pdf$/i.test(value)) throw new Error("Enter a PDF basename.");
  return value;
}

function directory(value: string): string {
  if (!isAbsolute(value)) throw new Error("Use an absolute path for private storage.");
  const path = realpathSync(value);
  if (!statSync(path).isDirectory()) throw new Error("Use an existing directory for private storage.");
  return path;
}

export function loadConfig(env: NodeJS.ProcessEnv = process.env): Config {
  const path = env.BLOCKSIGN_SHOPIFY_CONFIG;
  if (!path) throw new Error("Set BLOCKSIGN_SHOPIFY_CONFIG to the private configuration file.");
  const local = JSON.parse(readFileSync(path, "utf8")) as Partial<Config>;
  const { SHOPIFY_CLIENT_ID: appId, SHOPIFY_CLIENT_SECRET: appSecret, BLOCKSIGN_ESIGN_API_KEY: blocksignKey } = env;
  if (!appId || !appSecret || !blocksignKey || !/^[!-~]{1,512}$/.test(blocksignKey)
    || !local.shop || !/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/.test(local.shop)
    || !local.files || !local.db || !isAbsolute(local.db) || !Array.isArray(local.rules)) {
    throw new Error("Configure the Shopify store, app credentials, private database and BlockSign connection.");
  }
  const files = directory(local.files);
  const archive = local.archive ? directory(local.archive) : undefined;
  const slugs = new Set();
  const selectors = new Set();
  const rules = local.rules.map((rule): Rule => {
    const fields = [rule.variant, rule.product, rule.tag].filter(Boolean);
    if (fields.length > 1 || !["after_payment", "before_payment"].includes(rule.flow)
      || !["download", "shipment", "membership"].includes(rule.mode) || !Array.isArray(rule.documents)
      || rule.documents.length < 1 || rule.documents.length > 20
      || (rule.tag !== undefined && (typeof rule.tag !== "string" || !rule.tag.trim()))) {
      throw new Error("Invalid Shopify product rule.");
    }
    if (rule.variant) rule.variant = shopifyId("ProductVariant", rule.variant);
    if (rule.product) rule.product = shopifyId("Product", rule.product);
    const selector = rule.variant ?? rule.product ?? (rule.tag ? "tag:" + rule.tag : "global");
    if (selectors.has(selector)) throw new Error("Duplicate Shopify product rule.");
    selectors.add(selector);
    if (rule.flow === "before_payment") {
      if (!rule.variant || !rule.slug || !/^[a-z0-9][a-z0-9-]{0,70}$/.test(rule.slug) || slugs.has(rule.slug)) {
        throw new Error("Each before-payment rule needs one unique variant and purchase slug.");
      }
      slugs.add(rule.slug);
    }
    rule.documents = rule.documents.map((document) => ({ file: filename(document.file), placement: placement(document.placement) }));
    if (rule.mode !== "shipment") {
      rule.download = filename(rule.download ?? "download.pdf");
      if (rule.documents.some((document) => document.file.toLowerCase() === rule.download?.toLowerCase())) {
        throw new Error("Use separate files for confirmations and customer downloads.");
      }
    }
    return rule;
  });
  const port = Number(env.PORT || 3000);
  if (!Number.isSafeInteger(port) || port < 1 || port > 65535) throw new Error("Invalid PORT.");

  return { shop: local.shop, appSecret, appId, blocksignKey, files, archive, rules, db: resolve(directory(dirname(local.db)), local.db), port };
}

export function asset(root: string, file: string, limit = 16 * 1024 * 1024): Buffer {
  const path = realpathSync(join(root, filename(file)));
  const stat = statSync(path);
  if (dirname(path) !== root || !stat.isFile() || stat.size > limit) throw new Error("Private PDF unavailable.");
  const buffer = readFileSync(path);
  if (buffer.length > limit || buffer.subarray(0, 5).toString() !== "%PDF-") throw new Error("Invalid private PDF.");
  return buffer;
}

export function snapshot(rule: Rule, root: string): { documents: Document[]; download?: {file: string; hash: string} } {
  const documents: Document[] = rule.documents.map(({ file, placement: spec }) => ({
    file, hash: sha256(asset(root, file, 10 * 1024 * 1024)), placement: placement(spec), phase: "upload", completed: false,
  }));
  return {
    documents,
    download: rule.download ? { file: rule.download, hash: sha256(asset(root, rule.download)) } : undefined,
  };
}
