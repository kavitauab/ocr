import { type ReactNode } from "react";

interface TooltipProps {
  content: string;
  children: ReactNode;
  side?: "right" | "bottom" | "top" | "left";
  className?: string;
}

export function Tooltip({ content, children, side = "right", className = "" }: TooltipProps) {
  const positionClasses = {
    right: "left-full top-1/2 -translate-y-1/2 ml-2",
    left: "right-full top-1/2 -translate-y-1/2 mr-2",
    bottom: "top-full left-1/2 -translate-x-1/2 mt-2",
    top: "bottom-full left-1/2 -translate-x-1/2 mb-2",
  };

  return (
    <div className={`group relative inline-flex ${className}`}>
      {children}
      <div
        className={`pointer-events-none absolute ${positionClasses[side]} z-50 whitespace-nowrap rounded-md bg-foreground px-2.5 py-1 text-xs text-primary-foreground opacity-0 shadow-md transition-opacity group-hover:opacity-100`}
        role="tooltip"
      >
        {content}
      </div>
    </div>
  );
}
