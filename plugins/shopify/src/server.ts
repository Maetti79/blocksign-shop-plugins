import { createServer } from "node:http";
import type { IncomingMessage, ServerResponse } from "node:http";
import { pathToFileURL } from "node:url";
import { sha256, transport } from "../../../packages/typescript/src/blocksign.ts";
import type { Transport } from "../../../packages/typescript/src/blocksign.ts";
import { csrf, csrfValid, proxyIdentity, webhookValid } from "./auth.ts";
import { asset, loadConfig, shopifyId } from "./config.ts";
import type { Config } from "./config.ts";
import { queue } from "./queue.ts";
import { shopify } from "./shopify.ts";
import type { Shopify } from "./shopify.ts";
import { downloadable, service } from "./workflow.ts";
import { paid } from "./commerce.ts";

function escape(value: string): string {
  return value.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#39;");
}
function text(res: ServerResponse, status: number, body: string): void {
  res.writeHead(status, { "Content-Type": "text/plain; charset=utf-8" }).end(body);
}
function page(res: ServerResponse, title: string, content: string): void {
  res.writeHead(200, { "Content-Type": "text/html; charset=utf-8" }).end(
    '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
    + escape(title) + '</title><main><h1>' + escape(title) + '</h1>' + content + '</main></html>',
  );
}
function link(url: string, label: string): string {
  return '<a rel="noreferrer" href="' + escape(url) + '">' + escape(label) + "</a>";
}
async function body(req: IncomingMessage, limit: number): Promise<Buffer> {
  const chunks: Buffer[] = [];
  let length = 0;
  for await (const chunk of req) {
    length += chunk.length;
    if (length > limit) throw new Error("Request too large.");
    chunks.push(chunk);
  }
  return Buffer.concat(chunks, length);
}
function header(req: IncomingMessage, key: string): string {
  const value = req.headers[key];
  return typeof value === "string" ? value : "";
}

export function app(config: Config, api: Shopify = shopify(config), blocksign: Transport = transport(config.blocksignKey)) {
  const db = queue(config.db);
  const workflow = service(config, db, api, blocksign);
  const server = createServer(async (req, res) => {
    res.setHeader("Cache-Control", "private, no-store");
    res.setHeader("X-Content-Type-Options", "nosniff");
    res.setHeader("Referrer-Policy", "no-referrer");
    res.setHeader("X-Robots-Tag", "noindex, nofollow");
    try {
      const url = new URL(req.url ?? "/", "https://localhost");
      if (url.pathname === "/health" && req.method === "GET") return text(res, 200, "OK");
      if (url.pathname === "/" && req.method === "GET") {
        return page(res, "BlockSign eSign für Shopify", "<p>BlockSign-Status und Downloads stehen im Online-Shop unter /apps/blocksign zur Verfügung.</p>");
      }
      if (url.pathname === "/webhooks" && req.method === "POST") {
        const payload = await body(req, 2 * 1024 * 1024);
        if (header(req, "x-shopify-shop-domain") !== config.shop || !webhookValid(payload, header(req, "x-shopify-hmac-sha256"), config.appSecret)) {
          return text(res, 401, "Invalid webhook.");
        }
        const topic = header(req, "x-shopify-topic");
        if (!["orders/create", "orders/paid", "orders/updated", "orders/cancelled", "refunds/create"].includes(topic)) {
          return text(res, 404, "Unknown webhook.");
        }
        const event = JSON.parse(payload.toString("utf8"));
        const id = shopifyId("Order", topic === "refunds/create" ? event.order_id : event.admin_graphql_api_id ?? event.id);
        db.event(id);
        res.writeHead(204).end();
        return;
      }
      if (!url.pathname.startsWith("/proxy")) return text(res, 404, "Nicht gefunden.");
      const user = proxyIdentity(url.searchParams, config.appSecret, config.shop);
      if (!user) return text(res, 403, "Bitte im Shopify-Kundenkonto anmelden und die Seite erneut öffnen.");

      const purchase = url.pathname.match(/^\/proxy\/purchase\/([a-z0-9-]+)$/);
      if (purchase) {
        const slug = purchase[1];
        const rule = config.rules.find((entry) => entry.slug === slug && entry.flow === "before_payment");
        if (!rule) return text(res, 404, "Nicht gefunden.");
        if (req.method === "POST") {
          const form = new URLSearchParams((await body(req, 2048)).toString("utf8"));
          if (!csrfValid(form.get("token") ?? "", config.appSecret, user.customer, slug)) return text(res, 403, "Anfrage abgelaufen.");
          await workflow.startDraft(user.customer, rule);
          res.writeHead(303, { Location: user.prefix + "/purchase/" + slug }).end();
          return;
        }
        if (req.method !== "GET") return text(res, 405, "Methode unzulässig.");
        const job = db.findDraft(user.customer, slug);
        if (job?.kind === "draft" && job.phase === "invoice" && job.invoice) {
          return page(res, "Zahlung freigegeben", '<p>' + link(job.invoice, "Zum Shopify-Zahlungslink") + '</p>');
        }
        if (job?.kind === "draft" && job.phase === "invoice_pending_review") {
          return page(res, "Bestellung in Prüfung", "<p>Der Shop prüft die Erstellung des Zahlungslinks.</p>");
        }
        if (job?.kind === "draft" && job.expires > Date.now()) {
          return page(res, "BlockSign-Bestätigung ausstehend", "<p>Prüfe deine E-Mails auf die Einladung und lade die Seite nach der Bestätigung neu.</p>");
        }
        return page(res, "Vor der Zahlung bestätigen", '<form method="post" action="' + escape(user.prefix + "/purchase/" + slug)
          + '"><input type="hidden" name="token" value="' + csrf(config.appSecret, user.customer, slug)
          + '"><button type="submit">BlockSign-Bestätigung anfordern</button></form>');
      }

      if (req.method !== "GET") return text(res, 405, "Methode unzulässig.");
      if (url.pathname === "/proxy" || url.pathname === "/proxy/") {
        const orders = db.list(user.customer).filter((job) => job.kind === "order");
        return page(res, "BlockSign-Bestellungen", orders.length
          ? '<ul>' + orders.map((job) => {
            const id = job.id.split("/").at(-1) ?? "";
            return '<li>' + link(user.prefix + "/order/" + id, "Bestellung " + id) + '</li>';
          }).join("") + '</ul>'
          : '<p>Keine BlockSign-Bestellungen vorhanden.</p>');
      }
      const detail = url.pathname.match(/^\/proxy\/order\/([1-9][0-9]{0,19})$/);
      if (!detail) return text(res, 404, "Nicht gefunden.");
      const job = db.load(shopifyId("Order", detail[1]));
      if (!job || job.kind !== "order" || job.customer !== user.customer) return text(res, 404, "Nicht gefunden.");
      const order = await api.order(job.id);
      if (!order || order.customer?.id !== user.customer) return text(res, 404, "Nicht gefunden.");
      const ready = downloadable(order, job);
      const requested = url.searchParams.get("download");
      if (requested !== null) {
        const download = /^\d{1,3}$/.test(requested) ? job.downloads[Number(requested)] : undefined;
        if (!ready || !download) return text(res, 404, "Download nicht verfügbar.");
        const file = asset(config.files, download.file);
        if (sha256(file) !== download.hash) return text(res, 404, "Download nicht verfügbar.");
        res.writeHead(200, { "Content-Type": "application/pdf", "Content-Disposition": 'attachment; filename="' + download.file + '"', "Content-Length": file.length }).end(file);
        return;
      }
      const downloads = ready ? job.downloads.map((file, index) => '<li>' + link(user.prefix + "/order/" + detail[1] + "?download=" + index, file.file) + '</li>').join("") : "";
      return page(res, "BlockSign-Status", ready
        ? '<p>Die Bestätigung liegt vor.</p>' + (downloads ? '<ul>' + downloads + '</ul>' : '')
        : paid(order) && job.phase !== "excluded"
          ? '<p>Die Freigabe wartet auf die Bestätigung.</p>'
          : '<p>Die Bestellung ist noch nicht für die Auslieferung freigegeben.</p>');
    } catch {
      if (!res.headersSent) text(res, 503, "Der BlockSign-Dienst ist vorübergehend nicht verfügbar.");
      else res.destroy();
    }
  });
  server.requestTimeout = 30_000;
  server.headersTimeout = 15_000;
  return { server, tick: workflow.tick, close: db.close };
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  process.umask(0o077);
  const config = loadConfig();
  const runtime = app(config);
  runtime.server.listen(config.port, "0.0.0.0");
  const timer = setInterval(() => runtime.tick().catch(() => console.error("The Shopify BlockSign worker failed.")), 15_000);
  for (const signal of ["SIGINT", "SIGTERM"] as const) {
    process.once(signal, () => {
      clearInterval(timer);
      runtime.server.close(() => process.exit(0));
    });
  }
}
