import assert from "node:assert/strict";
import { createHmac } from "node:crypto";
import { mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { once } from "node:events";
import test from "node:test";
import { app } from "../plugins/shopify/src/server.ts";

const orderId = "gid://shopify/Order/410";
const draftOrderId = "gid://shopify/Order/411";
const freeOrderId = "gid://shopify/Order/412";
const productId = "gid://shopify/Product/110";
const variantId = "gid://shopify/ProductVariant/120";
const onlyDraftVariant = "gid://shopify/ProductVariant/121";
const customer = "gid://shopify/Customer/210";
const email = "customer@example.test";
const secret = "local-only-shopify-fixture-secret";
const shop = "blocksign-fixture.myshopify.com";
const documentId = "aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa";
const requestId = "bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb";

function proxy(customerId, extras = {}) {
  const parameters = {
    shop, logged_in_customer_id: String(customerId), path_prefix: "/apps/blocksign",
    timestamp: String(Math.floor(Date.now() / 1000)), ...extras,
  };
  const canonical = Object.entries(parameters).map(([key, value]) => key + "=" + value).sort().join("");
  return new URLSearchParams({ ...parameters, signature: createHmac("sha256", secret).update(canonical).digest("hex") });
}

function amount(value) {
  return { shopMoney: { amount: value, currencyCode: "EUR" } };
}

function order(id, variant, price = "25.00") {
  return {
    id, email, customer: { id: customer }, cancelledAt: null, tags: [],
    displayFinancialStatus: "PENDING", totalPriceSet: amount(price), totalOutstandingSet: amount(price), totalRefundedSet: amount("0.00"),
    lineItems: { pageInfo: { hasNextPage: false }, nodes: [{
      id: "gid://shopify/LineItem/" + id.split("/").at(-1),
      quantity: 1, currentQuantity: 1, variant: { id: variant }, product: { id: productId, tags: [] },
    }] },
  };
}

test("Shopify verifies the checkout identity, waits for payment, releases downloads, and signs before draft invoices", async (t) => {
  t.mock.timers.enable({ apis: ["Date"], now: Date.now() });
  const root = mkdtempSync(join(tmpdir(), "blocksign-shopify-"));
  for (const file of ["agreement.pdf", "download.pdf"]) writeFileSync(join(root, file), "%PDF-local fixture\n%%EOF\n");
  const paidOrder = order(orderId, variantId);
  const manualOrder = order(draftOrderId, onlyDraftVariant);
  const freeOrder = order(freeOrderId, variantId, "0.00");
  const orders = new Map([[orderId, paidOrder], [draftOrderId, manualOrder], [freeOrderId, freeOrder]]);
  const calls = [];
  let confirmed = true;
  let issuedDraft;
  const api = {
    order: async (id) => orders.get(id) ?? null,
    customerEmail: async () => email,
    draft: async (job) => {
      assert.equal(job.customer, customer);
      assert.equal(job.variant, onlyDraftVariant);
      assert(job.documents.every((document) => document.completed));
      assert.equal(issuedDraft, undefined);
      issuedDraft = job.id;
      return { id: "gid://shopify/DraftOrder/510", invoiceUrl: "https://" + shop + "/invoices/example" };
    },
    draftOrder: async () => null,
    tagOrder: async () => assert.fail("No shipping tag expected for downloads"),
  };
  const client = async (method, path) => {
    calls.push([method, path]);
    if (path === "/api/v1/documents") return { document_id: documentId };
    if (path === "/api/v1/signature-requests") return { request_id: requestId };
    if (path.endsWith("/send")) return { sent_at: "2026-01-01T00:00:00Z" };
    return { request_id: requestId, document_id: documentId, completed: confirmed, signers: [
      { email, signed_at: "2026-01-01T00:00:00Z" },
    ] };
  };
  const runtime = app({
    shop, appSecret: secret, appId: "local", blocksignKey: "local", db: join(root, "state.sqlite"), port: 3000,
    files: root, rules: [
      { variant: variantId, flow: "after_payment", mode: "download", documents: [{ file: "agreement.pdf" }], download: "download.pdf" },
      { variant: onlyDraftVariant, flow: "before_payment", slug: "private-access", mode: "membership", documents: [{ file: "agreement.pdf" }], download: "download.pdf" },
    ],
  }, api, client);
  t.after(async () => {
    runtime.server.close();
    await once(runtime.server, "close");
    runtime.close();
    rmSync(root, { recursive: true, force: true });
  });
  runtime.server.listen(0, "127.0.0.1");
  await once(runtime.server, "listening");
  const origin = "http://127.0.0.1:" + runtime.server.address().port;
  const forBuyer = (path, params = {}, options) => fetch(origin + path + "?" + proxy(210, params), options);
  const webhook = (topic, id, valid = true) => {
    const payload = JSON.stringify({ admin_graphql_api_id: id });
    return fetch(origin + "/webhooks", {
      method: "POST", body: payload, headers: {
        "x-shopify-shop-domain": shop, "x-shopify-topic": topic,
        "x-shopify-hmac-sha256": valid ? createHmac("sha256", secret).update(payload).digest("base64") : "invalid",
      },
    });
  };

  assert.equal((await webhook("orders/create", orderId, false)).status, 401);
  await runtime.tick();
  assert.equal(calls.length, 0);
  assert.equal((await webhook("orders/create", orderId)).status, 204);
  await runtime.tick();
  assert.equal(calls.length, 0, "a pending payment cannot trigger an invitation");
  assert.equal((await forBuyer("/proxy/order/410", { download: "0" })).status, 404);

  paidOrder.displayFinancialStatus = "PAID";
  paidOrder.totalOutstandingSet = amount("0.00");
  assert.equal((await webhook("orders/paid", orderId)).status, 204);
  assert.equal((await webhook("orders/paid", orderId)).status, 204);
  await runtime.tick();
  assert.equal(calls.length, 4);
  const download = await forBuyer("/proxy/order/410", { download: "0" });
  assert.equal(download.status, 200);
  assert.equal(download.headers.get("cache-control"), "private, no-store");
  assert.match(await download.text(), /^%PDF-/);
  assert.equal((await fetch(origin + "/proxy/order/410?" + proxy(211, { download: "0" }))).status, 404);
  const tampered = proxy(211, { download: "0" });
  tampered.set("logged_in_customer_id", "210");
  assert.equal((await fetch(origin + "/proxy/order/410?" + tampered)).status, 403);

  paidOrder.totalRefundedSet = amount("5.00");
  assert.equal((await forBuyer("/proxy/order/410", { download: "0" })).status, 404, "refund blocks access before a webhook arrives");
  assert.equal((await webhook("orders/create", freeOrderId)).status, 204);
  await runtime.tick();
  assert.equal((await forBuyer("/proxy/order/412", { download: "0" })).status, 200);

  manualOrder.displayFinancialStatus = "PAID";
  manualOrder.totalOutstandingSet = amount("0.00");
  assert.equal((await webhook("orders/paid", draftOrderId)).status, 204);
  await runtime.tick();
  assert.equal((await forBuyer("/proxy/order/411", { download: "0" })).status, 404, "standard checkout cannot unlock an unsigned draft-only product");

  confirmed = false;
  const start = "/proxy/purchase/private-access";
  const form = await (await forBuyer(start)).text();
  const token = form.match(/name="token" value="([a-f0-9]+)"/)?.[1];
  assert(token);
  assert.equal((await forBuyer(start, {}, { method: "POST", body: "token=invalid" })).status, 403);
  assert.equal((await forBuyer(start, {}, { method: "POST", body: "token=" + token, redirect: "manual" })).status, 303);
  await runtime.tick();
  assert.equal(issuedDraft, undefined);
  assert.doesNotMatch(await (await forBuyer(start)).text(), /Zum Shopify-Zahlungslink/);

  t.mock.timers.setTime(Date.now() + 121_000);
  confirmed = true;
  await runtime.tick();
  assert(issuedDraft);
  assert.match(await (await forBuyer(start)).text(), /Zum Shopify-Zahlungslink/);
  manualOrder.tags = ["blocksign-pre-" + issuedDraft];
  assert.equal((await webhook("orders/paid", draftOrderId)).status, 204);
  await runtime.tick();
  assert.equal((await forBuyer("/proxy/order/411", { download: "0" })).status, 200);
});

test("TypeScript workflow supports electronic proof of delivery", async () => {
  const { advance, sha256 } = await import("../packages/typescript/src/blocksign.ts");
  const file = Buffer.from("%PDF-fixture");
  const document = { file: "notice.pdf", hash: sha256(file), placement: { signature_level: "pod", page: 1, x: 50, y: 50 }, phase: "upload", completed: false };
  const phases = [];
  const paths = [];
  await advance(document, email, () => phases.push(document.phase), async () => file, async (method, uri) => {
    paths.push(uri);
    if (uri === "/api/upload-document") return { uuid: documentId };
    if (uri === "/api/invite-for-sign") return { uuid: documentId, email, success: "invitation email sent" };
    return { success: "Document is signed" };
  });
  assert(document.completed);
  assert(phases.includes("delivery_invite_pending_review"));
  assert(paths.at(-1).includes("signed_status?"));
});
