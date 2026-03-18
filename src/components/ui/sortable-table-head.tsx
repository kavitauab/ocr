import { TableHead } from "./table";
import { ArrowUp, ArrowDown, ArrowUpDown } from "lucide-react";

interface SortableTableHeadProps {
  field: string;
  current: string;
  onSort: (field: string) => void;
  children: React.ReactNode;
  className?: string;
}

export function SortableTableHead({ field, current, onSort, children, className = "" }: SortableTableHeadProps) {
  const isActive = current === field || current === `-${field}`;
  const isDesc = current === `-${field}`;

  const handleClick = () => {
    if (current === `-${field}`) {
      onSort(field); // switch to ASC
    } else {
      onSort(`-${field}`); // switch to DESC (or set new column)
    }
  };

  return (
    <TableHead
      className={`font-semibold cursor-pointer select-none hover:bg-muted/50 transition-colors ${className}`}
      onClick={handleClick}
    >
      <span className="inline-flex items-center gap-1">
        {children}
        {isActive ? (
          isDesc ? <ArrowDown className="h-3 w-3 text-foreground" /> : <ArrowUp className="h-3 w-3 text-foreground" />
        ) : (
          <ArrowUpDown className="h-3 w-3 text-muted-foreground/50" />
        )}
      </span>
    </TableHead>
  );
}
