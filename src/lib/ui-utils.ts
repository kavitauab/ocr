/**
 * Get consistent status badge classes across all pages
 */
export function getStatusClasses(status: string): string {
  const s = (status || "").toLowerCase();
  if (["completed", "processed", "active", "paid", "ok", "current"].includes(s))
    return "bg-emerald-50 text-emerald-700 border-emerald-200";
  if (["queued"].includes(s))
    return "bg-indigo-50 text-indigo-700 border-indigo-200";
  if (["processing", "pending", "uploading"].includes(s))
    return "bg-amber-50 text-amber-700 border-amber-200";
  if (["retrying"].includes(s))
    return "bg-orange-50 text-orange-700 border-orange-200";
  if (["failed", "error", "cancelled", "rejected", "overdue"].includes(s))
    return "bg-red-50 text-red-700 border-red-200";
  if (["suspended", "paused", "inactive"].includes(s))
    return "bg-orange-50 text-orange-700 border-orange-200";
  return "bg-slate-50 text-slate-600 border-slate-200";
}

/**
 * Format a date as relative time (e.g., "2 hours ago", "yesterday")
 */
export function formatRelativeTime(dateStr: string | null | undefined): string {
  if (!dateStr) return "";
  const date = new Date(dateStr);
  if (Number.isNaN(date.getTime())) return "";

  const now = new Date();
  const diffMs = now.getTime() - date.getTime();
  const diffSec = Math.floor(diffMs / 1000);
  const diffMin = Math.floor(diffSec / 60);
  const diffHr = Math.floor(diffMin / 60);
  const diffDay = Math.floor(diffHr / 24);

  if (diffSec < 60) return "just now";
  if (diffMin < 60) return `${diffMin}m ago`;
  if (diffHr < 24) return `${diffHr}h ago`;
  if (diffDay === 1) return "yesterday";
  if (diffDay < 7) return `${diffDay}d ago`;
  if (diffDay < 30) return `${Math.floor(diffDay / 7)}w ago`;
  return date.toLocaleDateString("lt-LT");
}

/**
 * Format datetime consistently across all pages
 */
export function formatDateTime(value: string | null | undefined): string {
  if (!value) return "\u2014";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "\u2014";
  return date.toLocaleString("lt-LT", { dateStyle: "short", timeStyle: "short" });
}

/**
 * Format a number with commas
 */
export function formatNumber(value: number | string | null | undefined): string {
  if (value === null || value === undefined || value === "") return "\u2014";
  const num = typeof value === "number" ? value : Number(value);
  if (Number.isNaN(num)) return "\u2014";
  return new Intl.NumberFormat("en-US").format(num);
}

/**
 * Format USD currency
 */
export function formatUsd(value: number | string | null | undefined): string {
  if (value === null || value === undefined || value === "") return "\u2014";
  const num = typeof value === "number" ? value : Number(value);
  if (Number.isNaN(num)) return "\u2014";
  return new Intl.NumberFormat("en-US", { style: "currency", currency: "USD", maximumFractionDigits: 4 }).format(num);
}
