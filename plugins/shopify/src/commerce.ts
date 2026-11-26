import type { Config, Rule } from "./config.ts";
import type { Document } from "../../../packages/typescript/src/blocksign.ts";

export interface OrderLine {
  id: string;
  quantity: number;
  currentQuantity: number;
  variant: { id: string } | null;
  product: { id: string; tags: string[] } | null;
}
export interface Order {
  id: string;
  email: string | null;
  customer: { id: string } | null;
  cancelledAt: string | null;
  tags: string[];
  displayFinancialStatus: string;
  totalPriceSet: Money;
  totalOutstandingSet: Money;
  totalRefundedSet: Money;
  lineItems: { nodes: OrderLine[]; pageInfo: { hasNextPage: boolean } };
}
interface Money { shopMoney: { amount: string; currencyCode: string } }

export interface ConfirmationJob {
  id: string;
  customer: string;
  email: string;
  documents: Document[];
  expires: number;
  archived?: string[];
}
export interface DraftJob extends ConfirmationJob {
  kind: "draft";
  slug: string;
  variant: string;
  phase: "confirming" | "invoice_pending_review" | "invoice";
  invoice?: string;
  draftId?: string;
  download?: { file: string; hash: string };
  mode: Rule["mode"];
  order?: string;
}
export interface OrderJob extends ConfirmationJob {
  kind: "order";
  phase: "waiting" | "ready" | "excluded";
  amount: string;
  currency: string;
  lines: { id: string; quantity: number; variant: string | null }[];
  downloads: { file: string; hash: string }[];
  shipment: boolean;
  tagged?: boolean;
  draft?: string;
}
export type Job = DraftJob | OrderJob;

export const apiVersion = "2026-07";
export const tagPrefix = "blocksign-pre-";

export function paid(order: Order): boolean {
  return !order.cancelledAt && Number(order.totalRefundedSet.shopMoney.amount) === 0
    && (Number(order.totalPriceSet.shopMoney.amount) === 0
      || (order.displayFinancialStatus === "PAID" && Number(order.totalOutstandingSet.shopMoney.amount) === 0))
    && !["REFUNDED", "PARTIALLY_REFUNDED", "VOIDED"].includes(order.displayFinancialStatus);
}

export function matches(order: Order, job: OrderJob): boolean {
  return !order.lineItems.pageInfo.hasNextPage && order.customer?.id === job.customer
    && order.totalPriceSet.shopMoney.amount === job.amount && order.totalPriceSet.shopMoney.currencyCode === job.currency
    && order.lineItems.nodes.length === job.lines.length
    && job.lines.every((item) => order.lineItems.nodes.some((line) =>
      line.id === item.id && line.quantity === item.quantity && line.currentQuantity === item.quantity
      && (line.variant?.id ?? null) === item.variant));
}

export function chooseRule(config: Pick<Config, "rules">, line: OrderLine): Rule | undefined {
  const rules = config.rules;
  return rules.find((rule) => rule.variant && rule.variant === line.variant?.id)
    ?? rules.find((rule) => rule.product && rule.product === line.product?.id)
    ?? rules.find((rule) => rule.tag && line.product?.tags.includes(rule.tag))
    ?? rules.find((rule) => !rule.variant && !rule.product && !rule.tag);
}
