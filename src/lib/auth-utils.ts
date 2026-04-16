/**
 * Null-safe access to the stored auth token.
 * Wrap all localStorage.getItem("token") usages so callers can't forget to
 * handle the missing/expired case.
 */
export function getToken(): string | null {
  try {
    const v = localStorage.getItem("token");
    return v && v !== "null" && v !== "undefined" ? v : null;
  } catch {
    return null;
  }
}

/**
 * Build a URL that embeds the access token as a query param.
 * Returns null (instead of a URL with "null" token) when the caller is
 * unauthenticated — callers should disable the link/button in that case.
 */
export function authorizedUrl(path: string, extraParams?: Record<string, string>): string | null {
  const token = getToken();
  if (!token) return null;
  const params = new URLSearchParams(extraParams || {});
  params.set("access_token", token);
  const sep = path.includes("?") ? "&" : "?";
  return `${path}${sep}${params.toString()}`;
}
