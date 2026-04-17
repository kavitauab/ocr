import { type LucideIcon } from "lucide-react";
import { type ReactNode } from "react";

interface Props {
  icon?: LucideIcon;
  title: string;
  description?: string;
  action?: ReactNode;
  compact?: boolean;
}

/**
 * Reusable empty-state block for pages that have no data to show.
 * Keeps the empty-state visual identity consistent across the app.
 */
export function EmptyState({ icon: Icon, title, description, action, compact = false }: Props) {
  return (
    <div className={`flex flex-col items-center justify-center text-center ${compact ? "py-6" : "py-12"}`}>
      {Icon && (
        <div className="rounded-full bg-muted p-3 mb-3">
          <Icon className="h-5 w-5 text-muted-foreground" />
        </div>
      )}
      <p className="text-sm font-medium text-foreground">{title}</p>
      {description && (
        <p className="text-xs text-muted-foreground mt-1 max-w-sm">{description}</p>
      )}
      {action && <div className="mt-3">{action}</div>}
    </div>
  );
}
