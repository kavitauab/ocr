interface ProgressProps {
  value: number;
  max?: number;
  className?: string;
  variant?: "default" | "success" | "danger";
}

export function Progress({ value, max = 100, className = "", variant = "default" }: ProgressProps) {
  const pct = Math.min(100, Math.max(0, (value / max) * 100));

  const barColors = {
    default: "bg-primary",
    success: "bg-emerald-500",
    danger: "bg-red-500",
  };

  return (
    <div className={`h-2 w-full overflow-hidden rounded-full bg-muted ${className}`}>
      <div
        className={`h-full rounded-full transition-all duration-300 ease-out ${barColors[variant]}`}
        style={{ width: `${pct}%` }}
      />
    </div>
  );
}
