import { DatabaseSync } from "node:sqlite";
import { randomUUID } from "node:crypto";
import { chmodSync } from "node:fs";
import type { Job } from "./commerce.ts";

export function queue(path: string) {
  const db = new DatabaseSync(path);
  if (path !== ":memory:") chmodSync(path, 0o600);
  db.exec(`
    PRAGMA journal_mode=WAL;
    PRAGMA busy_timeout=3000;
    CREATE TABLE IF NOT EXISTS order_events (id TEXT PRIMARY KEY, rev INTEGER NOT NULL, due INTEGER NOT NULL);
    CREATE TABLE IF NOT EXISTS jobs (id TEXT PRIMARY KEY, customer TEXT NOT NULL, state TEXT NOT NULL, due INTEGER NOT NULL);
    CREATE INDEX IF NOT EXISTS jobs_customer ON jobs (customer);
    CREATE UNIQUE INDEX IF NOT EXISTS open_purchase ON jobs(customer, json_extract(state,'$.slug'))
      WHERE json_extract(state,'$.kind') = 'draft' AND json_extract(state,'$.order') IS NULL;
    CREATE TABLE IF NOT EXISTS lock (id INTEGER PRIMARY KEY CHECK(id=1), token TEXT NOT NULL, expires INTEGER NOT NULL);
  `);
  return {
    event(id: string) {
      db.prepare("INSERT INTO order_events VALUES (?,1,?) ON CONFLICT(id) DO UPDATE SET rev=rev+1,due=excluded.due").run(id, Date.now());
    },
    events(): { id: string; rev: number }[] {
      return db.prepare("SELECT id, rev FROM order_events WHERE due <= ? ORDER BY due LIMIT 10").all(Date.now()) as { id: string; rev: number }[];
    },
    acknowledge(id: string, rev: number) {
      db.prepare("DELETE FROM order_events WHERE id=? AND rev=?").run(id, rev);
    },
    retry(id: string, rev: number) {
      db.prepare("UPDATE order_events SET due=? WHERE id=? AND rev=?").run(Date.now() + 60_000, id, rev);
    },
    load(id: string): Job | undefined {
      const row = db.prepare("SELECT state FROM jobs WHERE id=?").get(id);
      return row ? JSON.parse(String(row.state)) : undefined;
    },
    list(customer: string): Job[] {
      return db.prepare("SELECT state FROM jobs WHERE customer=? AND json_extract(state,'$.phase') <> 'excluded' ORDER BY rowid DESC LIMIT 30")
        .all(customer).map((row) => JSON.parse(String(row.state)));
    },
    findDraft(customer: string, slug: string): Job | undefined {
      const row = db.prepare(`SELECT state FROM jobs WHERE customer=? AND json_extract(state,'$.kind')='draft'
        AND json_extract(state,'$.slug')=? AND json_extract(state,'$.order') IS NULL`).get(customer, slug);
      return row ? JSON.parse(String(row.state)) : undefined;
    },
    draftForOrder(order: string): import("./commerce.ts").DraftJob | undefined {
      const row = db.prepare("SELECT state FROM jobs WHERE json_extract(state,'$.kind')='draft' AND json_extract(state,'$.order')=?").get(order);
      return row ? JSON.parse(String(row.state)) : undefined;
    },
    removeExpiredDraft(id: string) {
      db.prepare("DELETE FROM jobs WHERE id=? AND json_extract(state,'$.phase')='confirming' AND json_extract(state,'$.expires')<=?").run(id, Date.now());
    },
    insert(job: Job, due = Date.now()): boolean {
      return db.prepare("INSERT INTO jobs VALUES (?,?,?,?) ON CONFLICT DO NOTHING").run(job.id, job.customer, JSON.stringify(job), due).changes === 1;
    },
    save(job: Job, due: number) {
      if (db.prepare("UPDATE jobs SET state=?, due=? WHERE id=?").run(JSON.stringify(job), due, job.id).changes !== 1) {
        throw new Error("A BlockSign job is no longer available.");
      }
    },
    due(): Job[] {
      return db.prepare("SELECT state FROM jobs WHERE due>0 AND due<=? ORDER BY due LIMIT 10").all(Date.now()).map((row) => JSON.parse(String(row.state)));
    },
    lock(): string | undefined {
      const token = randomUUID();
      return db.prepare(`INSERT INTO lock (id,token,expires) VALUES (1,?,?) ON CONFLICT(id)
        DO UPDATE SET token=excluded.token,expires=excluded.expires WHERE lock.expires < ?`).run(token, Date.now() + 180_000, Date.now()).changes === 1 ? token : undefined;
    },
    renew(token: string): boolean {
      return db.prepare("UPDATE lock SET expires=? WHERE id=1 AND token=? AND expires >= ?").run(Date.now() + 180_000, token, Date.now()).changes === 1;
    },
    unlock(token: string) {
      db.prepare("DELETE FROM lock WHERE id=1 AND token=?").run(token);
    },
    close() { db.close(); },
  };
}
export type Queue = ReturnType<typeof queue>;
