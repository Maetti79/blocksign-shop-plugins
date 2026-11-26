import { apiVersion, tagPrefix } from "./commerce.ts";
import type { DraftJob, Order } from "./commerce.ts";
import type { Config } from "./config.ts";

type GraphQLPayload<T> = { data?: T; errors?: unknown[] };
type Mutation = { userErrors: unknown[] };
export interface Shopify {
  order(id: string): Promise<Order | null>;
  customerEmail(id: string): Promise<string | null>;
  draft(job: DraftJob): Promise<{ id: string; invoiceUrl: string }>;
  draftOrder(id: string): Promise<string | null>;
  tagOrder(id: string, add: boolean): Promise<void>;
}

export function shopify(config: Config): Shopify {
  let offlineToken: { value: string; expires: number } | undefined;
  const origin = "https://" + config.shop;

  async function token(): Promise<string> {
    if (offlineToken && Date.now() < offlineToken.expires) return offlineToken.value;
    const response = await fetch(origin + "/admin/oauth/access_token", {
      method: "POST", redirect: "error", signal: AbortSignal.timeout(15_000),
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({ grant_type: "client_credentials", client_id: config.appId, client_secret: config.appSecret }),
    });
    if (!response.ok) throw new Error("Shopify rejected the app credentials.");
    const result = await response.json() as { access_token?: string; expires_in?: number };
    if (typeof result.access_token !== "string" || !Number.isFinite(result.expires_in) || Number(result.expires_in) <= 60) {
      throw new Error("Invalid Shopify token response.");
    }
    offlineToken = { value: result.access_token, expires: Date.now() + (Number(result.expires_in) - 60) * 1000 };
    return offlineToken.value;
  }

  async function query<T>(source: string, variables: object): Promise<T> {
    const response = await fetch(origin + "/admin/api/" + apiVersion + "/graphql.json", {
      method: "POST", redirect: "error", signal: AbortSignal.timeout(20_000),
      headers: { "Content-Type": "application/json", "X-Shopify-Access-Token": await token() },
      body: JSON.stringify({ query: source, variables }),
    });
    if (!response.ok) {
      if (response.status === 401) offlineToken = undefined;
      throw new Error("Shopify Admin API request failed.");
    }
    const result = await response.json() as GraphQLPayload<T>;
    if (result.errors?.length || !result.data) throw new Error("Shopify rejected the Admin API operation.");
    return result.data;
  }

  return {
    async order(id) {
      const result = await query<{ order: Order | null }>(`query BlockSignOrder($id: ID!) {
        order(id: $id) {
          id email customer { id } cancelledAt tags displayFinancialStatus
          totalPriceSet { shopMoney { amount currencyCode } }
          totalOutstandingSet { shopMoney { amount currencyCode } }
          totalRefundedSet { shopMoney { amount currencyCode } }
          lineItems(first: 100) {
            pageInfo { hasNextPage }
            nodes { id quantity currentQuantity variant { id } product { id tags } }
          }
        }
      }`, { id });

      return result.order;
    },
    async customerEmail(id) {
      const result = await query<{ customer: { defaultEmailAddress?: { emailAddress: string } | null } | null }>(`query BlockSignCustomer($id: ID!) {
        customer(id: $id) { defaultEmailAddress { emailAddress } }
      }`, { id });

      return result.customer?.defaultEmailAddress?.emailAddress ?? null;
    },
    async draft(job) {
      const result = await query<{ draftOrderCreate: Mutation & { draftOrder?: { id: string; invoiceUrl: string } } }>(`mutation BlockSignInvoice($input: DraftOrderInput!) {
        draftOrderCreate(input: $input) { draftOrder { id invoiceUrl } userErrors { field message } }
      }`, {
        input: {
          purchasingEntity: { customerId: job.customer },
          lineItems: [{ variantId: job.variant, quantity: 1 }],
          tags: [tagPrefix + job.id],
        },
      });
      const created = result.draftOrderCreate.draftOrder;
      if (result.draftOrderCreate.userErrors.length || !created?.id || !created.invoiceUrl) throw new Error("Shopify could not create the payment invoice.");
      const url = new URL(created.invoiceUrl);
      if (url.protocol !== "https:" || url.username || url.password
        || (url.hostname !== config.shop && !url.hostname.endsWith(".shopify.com") && !url.hostname.endsWith(".myshopify.com"))) {
        throw new Error("Shopify returned an invalid payment invoice URL.");
      }
      return { id: created.id, invoiceUrl: url.href };
    },
    async draftOrder(id) {
      const result = await query<{ draftOrder: { order: { id: string } | null } | null }>(`query BlockSignInvoiceOrder($id: ID!) {
        draftOrder(id: $id) { order { id } }
      }`, { id });
      return result.draftOrder?.order?.id ?? null;
    },
    async tagOrder(id, add) {
      const operation = add ? "tagsAdd" : "tagsRemove";
      const result = await query<Record<"tagsAdd" | "tagsRemove", Mutation>>(`mutation BlockSignFulfillment($id: ID!) {
        ${operation}(id: $id, tags: ["blocksign-ready"]) { userErrors { field message } }
      }`, { id });
      if (result[operation].userErrors.length) throw new Error("Shopify could not update the fulfillment release.");
    },
  };
}
