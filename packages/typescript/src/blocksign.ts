import { createHash, randomBytes } from "node:crypto";

export type Level = "aes" | "fes" | "qes" | "pod";
export interface Placement {
  signature_level: Level;
  page: number;
  x: number;
  y: number;
}
export interface Document {
  file: string;
  hash: string;
  placement: Placement;
  phase: "upload" | "upload_pending_review" | "prepare" | "prepare_pending_review" | "send" | "status" | "delivery_invite" | "delivery_invite_pending_review" | "delivery_status";
  completed: boolean;
  documentId?: string;
  requestId?: string;
}
export class BlockSignResponseError extends Error {
  readonly status: number;
  constructor(message: string, status: number) {
    super(message);
    this.status = status;
  }
}

export function sha256(body: Uint8Array): string {
  return createHash("sha256").update(body).digest("hex");
}

export function placement(value: Partial<Placement> = {}): Placement {
  const settings = { signature_level: "aes" as Level, page: 1, x: 50, y: 50, ...value };
  if (!["aes", "fes", "qes", "pod"].includes(settings.signature_level)
      || !Number.isSafeInteger(settings.page) || settings.page < 1
      || ![settings.x, settings.y].every((coordinate) => Number.isFinite(coordinate) && coordinate >= 0)) {
    throw new Error("Invalid BlockSign document placement.");
  }
  return settings;
}

function uuid(value: unknown): string {
  if (typeof value !== "string" || !/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i.test(value)) {
    throw new Error("Invalid BlockSign response identifier.");
  }
  return value;
}

export type Transport = (method: string, path: string, contentType: string, body?: string | Uint8Array<ArrayBuffer>) => Promise<unknown>;

export function transport(apiKey: string): Transport {
  return async (method, path, contentType, body) => {
    const response = await fetch("https://blocksign.io" + path, {
      method, body, redirect: "error", signal: AbortSignal.timeout(25_000),
      headers: { Authorization: "Bearer " + apiKey, "Content-Type": contentType, Accept: contentType === "application/pdf" ? contentType : "application/json" },
    });
    if (!response.ok) {
      await response.body?.cancel();
      throw new BlockSignResponseError("BlockSign API request failed.", response.status);
    }

    const limit = contentType === "application/pdf" ? 16 * 1024 * 1024 : 1024 * 1024;
    const chunks: Uint8Array[] = [];
    let bytes = 0;
    if (response.body) {
      const reader = response.body.getReader();
      try {
        for (;;) {
          const { value, done } = await reader.read();
          if (done) break;
          bytes += value.length;
          if (bytes > limit) {
            await reader.cancel();
            throw new Error("BlockSign response exceeds the size limit.");
          }
          chunks.push(value);
        }
      } finally {
        reader.releaseLock();
      }
    }
    const data = Buffer.concat(chunks);
    return contentType === "application/pdf" ? data : JSON.parse(data.toString("utf8"));
  };
}

function json(value: unknown): Record<string, unknown> {
  if (!value || typeof value !== "object" || Array.isArray(value)) throw new Error("Invalid BlockSign response.");
  return value as Record<string, unknown>;
}

export async function advance(
  document: Document, email: string, persist: () => void, read: () => Promise<Buffer>, request: Transport,
): Promise<boolean> {
  if (document.completed || document.phase.endsWith("_pending_review")) return false;
  const delivery = document.placement.signature_level === "pod";
  const stage = (phase: Document["phase"]): void => {
    document.phase = phase;
    persist();
  };
  if (document.phase === "upload") {
    const pdf = await read();
    if (pdf.length > 10 * 1024 * 1024 || sha256(pdf) !== document.hash) throw new Error("Agreement content has changed.");
    const boundary = randomBytes(24).toString("hex");
    const body = Buffer.concat([
      Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="file"; filename="agreement.pdf"\r\nContent-Type: application/pdf\r\n\r\n`),
      pdf, Buffer.from(`\r\n--${boundary}--\r\n`),
    ]);
    stage("upload_pending_review");
    const uploaded = json(await request("POST", delivery ? "/api/upload-document" : "/api/v1/documents", "multipart/form-data; boundary=" + boundary, body));
    document.documentId = uuid(uploaded[delivery ? "uuid" : "document_id"]);
    stage(delivery ? "delivery_invite" : "prepare");
  }

  if (document.phase === "prepare" || document.phase === "delivery_invite") {
    const sendingDelivery = document.phase === "delivery_invite";
    const fileId = uuid(document.documentId);
    stage(sendingDelivery ? "delivery_invite_pending_review" : "prepare_pending_review");
    try {
      if (sendingDelivery) {
        const invitation = json(await request("POST", "/api/invite-for-sign", "application/json", JSON.stringify({ uuid: fileId, email, type: "pod" })));
        if (invitation.uuid !== fileId || String(invitation.email).toLowerCase() !== email.toLowerCase()
            || invitation.success !== "invitation email sent") throw new Error("BlockSign did not confirm the expected recipient.");
        stage("delivery_status");
      } else {
        const prepared = json(await request("POST", "/api/v1/signature-requests", "application/json", JSON.stringify({
          document_id: fileId, signer_email: email, ...document.placement,
        })));
        document.requestId = uuid(prepared.request_id);
        stage("send");
      }
    } catch (error) {
      if (!(error instanceof BlockSignResponseError) || error.status !== 409) throw error;
      stage(sendingDelivery ? "delivery_invite" : "prepare");
      return true;
    }
  }

  if (document.phase === "send") {
    const sent = json(await request("POST", `/api/v1/signature-requests/${uuid(document.requestId)}/send`, "application/json"));
    if (!sent.sent_at && sent.completed !== true) return true;
    stage("status");
  }

  if (document.phase === "status") {
    const status = json(await request("GET", `/api/v1/signature-requests/${uuid(document.requestId)}`, "application/json"));
    if (status.request_id !== document.requestId || status.document_id !== document.documentId) {
      throw new Error("BlockSign status does not match the requested document.");
    }
    if (status.completed === true) {
      if (!Array.isArray(status.signers) || !status.signers.some((signer) => signer?.signed_at && typeof signer.email === "string" && signer.email.toLowerCase() === email.toLowerCase())) {
        throw new Error("BlockSign has not confirmed the expected signer.");
      }
      document.completed = true;
      persist();
      return false;
    }
    return !["canceled", "cancelled", "declined", "expired"].includes(String(status.status));
  }

  if (document.phase === "delivery_status") {
    const query = new URLSearchParams({ uuid: uuid(document.documentId), email });
    try {
      const delivered = json(await request("GET", "/api/documents/signed_status?" + query, "application/json"));
      if (delivered.success !== "Document is signed") return true;
    } catch (error) {
      if (error instanceof BlockSignResponseError && error.status === 404) return true;
      throw error;
    }
    document.completed = true;
    persist();
  }
  return false;
}

export async function evidence(document: Document, request: Transport): Promise<{ name: string; content: Buffer }[]> {
  if (!document.completed) throw new Error("Unconfirmed BlockSign document.");
  const delivery = document.placement.signature_level === "pod";
  const id = uuid(delivery ? document.documentId : document.requestId);
  const path = delivery ? "/api/download-document?uuid=" + id : `/api/v1/signature-requests/${id}/document`;
  const pdf = await request("GET", path, "application/pdf");
  if (!Buffer.isBuffer(pdf) || !pdf.subarray(0, 5).equals(Buffer.from("%PDF-"))
      || !/%%EOF[\x00\x09\x0a\x0c\x0d\x20]*$/.test(pdf.subarray(-1024).toString("latin1"))) {
    throw new Error("BlockSign did not return a complete PDF.");
  }
  if (delivery) return [{ name: id + ".delivery.pdf", content: pdf }];
  const audit = json(await request("GET", `/api/v1/signature-requests/${id}/audit-trail`, "application/json"));
  if (audit.request_id !== id || !Array.isArray(audit.signatures)) throw new Error("Invalid BlockSign audit trail.");
  return [
    { name: id + ".pdf", content: pdf },
    { name: id + ".audit.json", content: Buffer.from(JSON.stringify(audit, null, 2) + "\n") },
  ];
}
