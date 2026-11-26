import { randomUUID } from "node:crypto";
import { lstatSync, mkdirSync, renameSync, rmSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { advance, evidence } from "../../../packages/typescript/src/blocksign.ts";
import type { Transport } from "../../../packages/typescript/src/blocksign.ts";
import { allows } from "../../../packages/typescript/src/gate.ts";
import { asset, snapshot } from "./config.ts";
import type { Config, Rule } from "./config.ts";
import { chooseRule, matches, paid, tagPrefix } from "./commerce.ts";
import type { DraftJob, Job, Order, OrderJob } from "./commerce.ts";
import type { Queue } from "./queue.ts";
import type { Shopify } from "./shopify.ts";

const minute = 60_000;
const week = 7 * 24 * 60 * minute;
const validEmail = (email: string | null | undefined): email is string => !!email && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);

export function downloadable(order: Order, job: OrderJob): boolean {
  return job.phase === "ready" && paid(order) && matches(order, job)
    && allows("fulfillment", job.documents.map((document) => ({ timing: "after_payment", completed: document.completed })));
}

export function service(config: Config, db: Queue, api: Shopify, blocksign: Transport) {
  async function startDraft(customer: string, rule: Rule): Promise<DraftJob> {
    if (rule.flow !== "before_payment" || !rule.slug || !rule.variant) throw new Error("Invalid BlockSign purchase.");
    const previous = db.findDraft(customer, rule.slug);
    if (previous?.kind === "draft" && (previous.expires > Date.now() || previous.phase !== "confirming")) return previous;
    if (previous?.kind === "draft") db.removeExpiredDraft(previous.id);
    const email = await api.customerEmail(customer);
    if (!validEmail(email)) throw new Error("A verified Shopify customer email is required.");
    const state = snapshot(rule, config.files);
    const job: DraftJob = {
      ...state,
      id: randomUUID(), kind: "draft", phase: "confirming", customer, email,
      slug: rule.slug, variant: rule.variant, mode: rule.mode, expires: Date.now() + week,
    };
    if (!db.insert(job)) {
      const existing = db.findDraft(customer, rule.slug);
      if (existing?.kind === "draft") return existing;
      throw new Error("Could not create a BlockSign confirmation.");
    }
    return job;
  }

  function relatedDraft(order: Order): DraftJob | undefined {
    for (const tag of order.tags) {
      if (!tag.startsWith(tagPrefix)) continue;
      const result = db.load(tag.slice(tagPrefix.length));
      if (result?.kind === "draft") return result;
    }
    return db.draftForOrder(order.id);
  }

  async function reconcile(id: string): Promise<void> {
    const order = await api.order(id);
    const existing = db.load(id);
    if (existing?.kind === "order") {
      if (!order || !matches(order, existing) || order.cancelledAt || Number(order.totalRefundedSet.shopMoney.amount) !== 0) {
        db.save({ ...existing, phase: "excluded" }, 0);
        if (order && existing.shipment && order.tags.includes("blocksign-ready")) await api.tagOrder(id, false);
        return;
      }
      if (existing.phase !== "excluded" && paid(order)) {
        db.save({ ...existing, expires: existing.expires || Date.now() + week }, Date.now());
      }
      return;
    }
    if (!order || order.cancelledAt || order.lineItems.pageInfo.hasNextPage || Number(order.totalRefundedSet.shopMoney.amount) !== 0) return;
    const lines = order.lineItems.nodes;
    const rules = lines.map((line) => chooseRule(config, line));
    if (!rules.some(Boolean)) return;
    const customer = order.customer?.id;
    if (!customer || !validEmail(order.email)) throw new Error("Shopify protected customer data is unavailable.");

    const requiresDraft = rules.some((rule) => rule?.flow === "before_payment");
    const draft = requiresDraft ? relatedDraft(order) : undefined;
    if (requiresDraft && (!draft || !draft.draftId || draft.phase !== "invoice"
      || (draft.order && draft.order !== order.id) || lines.length !== 1 || lines[0].variant?.id !== draft.variant
      || lines[0].quantity !== 1 || draft.customer !== customer || draft.email.toLowerCase() !== order.email.toLowerCase()
      || !allows("payment", draft.documents.map((document) => ({ timing: "before_payment", completed: document.completed }))))) {
      return;
    }
    const documents = new Map();
    const downloads = new Map();
    let shipment = false;
    for (const rule of rules) {
      if (!rule) continue;
      const state = rule.flow === "before_payment" ? draft! : snapshot(rule, config.files);
      for (const document of state.documents) {
        documents.set(JSON.stringify([document.file, document.hash, document.placement]), document);
      }
      if (state.download) downloads.set(state.download.hash, state.download);
      shipment ||= rule.mode === "shipment";
    }

    const job: OrderJob = {
      id, kind: "order", customer, email: order.email, phase: "waiting",
      amount: order.totalPriceSet.shopMoney.amount, currency: order.totalPriceSet.shopMoney.currencyCode,
      lines: lines.map((line) => ({ id: line.id, quantity: line.quantity, variant: line.variant?.id ?? null })),
      documents: [...documents.values()], downloads: [...downloads.values()],
      shipment, expires: paid(order) ? Date.now() + week : 0, draft: draft?.id,
    };
    db.insert(job, paid(order) ? Date.now() : 0);
    if (draft) db.save({ ...draft, order: id }, 0);
  }

  async function archive(job: OrderJob, persist: () => void): Promise<void> {
    if (!config.archive) return;
    const document = job.documents.find((item) => !(job.archived ?? []).includes(item.documentId ?? ""));
    if (!document?.documentId) return;
    const folder = join(config.archive, "shopify-" + job.id.split("/").at(-1));
    mkdirSync(folder, { recursive: true, mode: 0o700 });
    const stat = lstatSync(folder);
    if (!stat.isDirectory() || stat.isSymbolicLink() || (stat.mode & 0o077) !== 0) throw new Error("Invalid private Shopify archive.");
    for (const file of await evidence(document, blocksign)) {
      const temporary = join(folder, ".blocksign-" + randomUUID());
      try {
        writeFileSync(temporary, file.content, { mode: 0o600, flag: "wx" });
        renameSync(temporary, join(folder, file.name));
      } finally {
        rmSync(temporary, { force: true });
      }
    }
    job.archived = [...(job.archived ?? []), document.documentId];
    persist();
  }

  async function process(job: Job): Promise<void> {
    const persist = () => db.save(job, Date.now());
    const pending = job.documents.find((document) => !document.completed);
    if (job.kind === "order") {
      const order = await api.order(job.id);
      if (!order || !matches(order, job) || order.cancelledAt || Number(order.totalRefundedSet.shopMoney.amount) !== 0) {
        job.phase = "excluded";
        db.save(job, 0);
        if (order && job.shipment && order.tags.includes("blocksign-ready")) await api.tagOrder(job.id, false);
        return;
      }
      if (!paid(order)) {
        if (job.shipment && order.tags.includes("blocksign-ready")) await api.tagOrder(job.id, false);
        db.save(job, 0);
        return;
      }
    }
    if (job.kind === "draft" && job.phase === "invoice") {
      const linked = job.draftId ? await api.draftOrder(job.draftId) : null;
      if (linked) {
        job.order = linked;
        db.event(linked);
      }
      db.save(job, linked ? 0 : Date.now() + 5 * minute);
      return;
    }
    if (Date.now() > job.expires || pending?.phase.endsWith("_pending_review") || job.phase === "invoice_pending_review") {
      db.save(job, 0);
      return;
    }
    if (pending) {
      const retry = await advance(pending, job.email, persist, async () => asset(config.files, pending.file, 10 * 1024 * 1024), blocksign);
      if (retry || !pending.completed) {
        db.save(job, retry ? Date.now() + 2 * minute : 0);
        return;
      }
      if (job.documents.some((document) => !document.completed)) {
        db.save(job, Date.now() + 1000);
        return;
      }
    }

    if (job.kind === "draft") {
      const current = await api.customerEmail(job.customer);
      if (Date.now() > job.expires || !validEmail(current) || current.toLowerCase() !== job.email.toLowerCase()) {
        db.save(job, 0);
        return;
      }
      job.phase = "invoice_pending_review";
      persist();
      const draft = await api.draft(job);
      job.invoice = draft.invoiceUrl;
      job.draftId = draft.id;
      job.phase = "invoice";
      db.save(job, Date.now() + 5 * minute);
      return;
    }
    job.phase = "ready";
    persist();
    if (job.shipment && !job.tagged) {
      const order = await api.order(job.id);
      if (order && downloadable(order, job)) {
        await api.tagOrder(job.id, true);
        job.tagged = true;
        persist();
      }
    }
    await archive(job, persist);
    const moreEvidence = config.archive && job.documents.some((document) => !(job.archived ?? []).includes(document.documentId ?? ""));
    db.save(job, moreEvidence ? Date.now() + 1000 : 0);
  }

  async function tick(): Promise<void> {
    const lock = db.lock();
    if (!lock) return;
    try {
      for (const event of db.events()) {
        if (!db.renew(lock)) return;
        try {
          await reconcile(event.id);
          db.acknowledge(event.id, event.rev);
        } catch {
          db.retry(event.id, event.rev);
          console.warn("BlockSign could not reconcile a Shopify order.");
        }
      }
      for (const job of db.due()) {
        if (!db.renew(lock)) return;
        try {
          await process(job);
        } catch {
          const held = job.phase === "invoice_pending_review" || job.documents.some((document) => document.phase.endsWith("_pending_review"));
          db.save(job, held ? 0 : Date.now() + 2 * minute);
          console.warn(held ? "A Shopify BlockSign job requires manual review." : "A Shopify BlockSign job will retry.");
        }
      }
    } finally {
      db.unlock(lock);
    }
  }

  return { startDraft, tick };
}
