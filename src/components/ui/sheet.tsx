import { useEffect, useRef, type ReactNode } from "react";

interface SheetProps {
  open: boolean;
  onClose: () => void;
  side?: "left" | "right";
  children: ReactNode;
  className?: string;
}

export function Sheet({ open, onClose, side = "left", children, className = "" }: SheetProps) {
  const overlayRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (open) {
      document.body.style.overflow = "hidden";
    } else {
      document.body.style.overflow = "";
    }
    return () => { document.body.style.overflow = ""; };
  }, [open]);

  useEffect(() => {
    const handleEsc = (e: KeyboardEvent) => {
      if (e.key === "Escape" && open) onClose();
    };
    document.addEventListener("keydown", handleEsc);
    return () => document.removeEventListener("keydown", handleEsc);
  }, [open, onClose]);

  if (!open) return null;

  const slideClass = side === "left"
    ? "left-0 animate-slide-in"
    : "right-0 animate-slide-in";

  return (
    <div className="fixed inset-0 z-50" ref={overlayRef}>
      <div
        className="absolute inset-0 bg-black/40 backdrop-blur-[2px] animate-fade-in"
        onClick={onClose}
      />
      <div
        className={`absolute top-0 bottom-0 ${slideClass} w-72 bg-card shadow-2xl flex flex-col ${className}`}
      >
        {children}
      </div>
    </div>
  );
}
