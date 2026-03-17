import { useState, useCallback } from "react";
import { Link } from "react-router-dom";
import { useDropzone } from "react-dropzone";
import { useCompany } from "@/lib/company";
import { uploadInvoice } from "@/api/client";
import { Card, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Progress } from "@/components/ui/progress";
import { toast } from "sonner";
import {
  Upload as UploadIcon,
  Loader2,
  CheckCircle,
  XCircle,
  FileText,
  ArrowRight,
  ShieldAlert,
  X,
} from "lucide-react";

interface UploadResult {
  file: string;
  status: "pending" | "uploading" | "done" | "error";
  invoiceId?: string;
  vendor?: string;
  error?: string;
}

export default function Upload() {
  const { selectedCompany, hasCompanyRole } = useCompany();
  const [uploading, setUploading] = useState(false);
  const [results, setResults] = useState<UploadResult[]>([]);

  if (!hasCompanyRole("manager")) {
    return (
      <div className="space-y-4">
        <h1 className="text-2xl font-bold tracking-tight text-foreground">Upload Invoice</h1>
        <Card>
          <CardContent className="py-16">
            <div className="flex flex-col items-center justify-center text-center">
              <div className="rounded-full bg-red-50 p-4 mb-3">
                <ShieldAlert className="h-8 w-8 text-red-500" />
              </div>
              <p className="text-sm font-medium text-foreground">Permission denied</p>
              <p className="text-xs text-muted-foreground mt-1 max-w-sm">
                You don't have permission to upload invoices. Contact your company admin for manager access.
              </p>
            </div>
          </CardContent>
        </Card>
      </div>
    );
  }

  const onDrop = useCallback(async (files: File[]) => {
    if (!selectedCompany) {
      toast.error("Please select a company first");
      return;
    }

    const initial: UploadResult[] = files.map((f) => ({ file: f.name, status: "pending" }));
    setResults(initial);
    setUploading(true);

    for (let i = 0; i < files.length; i++) {
      setResults((prev) => prev.map((r, idx) => idx === i ? { ...r, status: "uploading" } : r));

      try {
        const result: any = await uploadInvoice(files[i], selectedCompany.id);
        setResults((prev) =>
          prev.map((r, idx) =>
            idx === i
              ? { ...r, status: "done", invoiceId: result.invoice?.id, vendor: result.invoice?.vendorName }
              : r
          )
        );
      } catch (err: any) {
        const errorMsg = err.response?.data?.error || "Failed to upload";
        setResults((prev) => prev.map((r, idx) => idx === i ? { ...r, status: "error", error: errorMsg } : r));
      }
    }

    setUploading(false);
    const doneCount = files.length;
    toast.success(`Processed ${doneCount} file${doneCount > 1 ? "s" : ""}`);
  }, [selectedCompany]);

  const { getRootProps, getInputProps, isDragActive } = useDropzone({
    onDrop,
    accept: { "application/pdf": [".pdf"], "image/png": [".png"], "image/jpeg": [".jpg", ".jpeg"] },
    maxSize: 20 * 1024 * 1024,
    multiple: true,
    disabled: uploading,
  });

  const clearResults = () => setResults([]);
  const doneCount = results.filter((r) => r.status === "done").length;
  const errorCount = results.filter((r) => r.status === "error").length;
  const progressPct = results.length > 0 ? ((doneCount + errorCount) / results.length) * 100 : 0;

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight text-foreground">Upload Invoices</h1>
        <p className="text-sm text-muted-foreground mt-0.5">
          Upload invoice files for AI-powered data extraction
        </p>
      </div>

      {/* Dropzone */}
      <Card className="overflow-hidden">
        <CardContent className="p-0">
          <div
            {...getRootProps()}
            className={`relative p-16 text-center cursor-pointer transition-all duration-200 ${
              isDragActive
                ? "bg-gradient-to-b from-primary/5 to-primary/[0.02] border-2 border-dashed border-primary/40"
                : "hover:bg-muted/30"
            } ${uploading ? "pointer-events-none opacity-60" : ""}`}
          >
            <input {...getInputProps()} />
            {uploading ? (
              <div className="flex flex-col items-center gap-3">
                <div className="relative">
                  <div className="h-16 w-16 rounded-2xl bg-primary/10 flex items-center justify-center">
                    <Loader2 className="h-8 w-8 text-primary animate-spin" />
                  </div>
                </div>
                <div>
                  <p className="text-sm font-medium text-foreground">
                    Processing {results.findIndex((r) => r.status === "uploading") + 1} of {results.length}
                  </p>
                  <div className="mt-3 w-64 mx-auto">
                    <Progress value={progressPct} />
                  </div>
                </div>
              </div>
            ) : (
              <div className="flex flex-col items-center gap-3">
                <div className={`h-16 w-16 rounded-2xl flex items-center justify-center transition-colors duration-200 ${
                  isDragActive ? "bg-primary/10" : "bg-muted"
                }`}>
                  <UploadIcon className={`h-8 w-8 transition-colors duration-200 ${
                    isDragActive ? "text-primary" : "text-muted-foreground"
                  }`} />
                </div>
                <div>
                  <p className="text-sm font-medium text-foreground">
                    {isDragActive ? "Drop files here" : "Drag & drop invoices, or click to browse"}
                  </p>
                  <p className="text-xs text-muted-foreground mt-1">Multiple files supported</p>
                </div>
                <div className="flex items-center gap-1.5 mt-1">
                  <Badge variant="outline" className="text-[10px] px-1.5 py-0">PDF</Badge>
                  <Badge variant="outline" className="text-[10px] px-1.5 py-0">PNG</Badge>
                  <Badge variant="outline" className="text-[10px] px-1.5 py-0">JPG</Badge>
                  <span className="text-[10px] text-muted-foreground">max 20MB</span>
                </div>
              </div>
            )}
          </div>
        </CardContent>
      </Card>

      {/* Results */}
      {results.length > 0 && (
        <Card className="overflow-hidden">
          <CardContent className="p-0">
            {/* Summary header */}
            <div className="flex items-center justify-between px-4 py-3 border-b border-border/60 bg-muted/30">
              <div className="flex items-center gap-3">
                <h3 className="text-sm font-semibold text-foreground">
                  {uploading ? "Processing..." : "Upload Results"}
                </h3>
                {!uploading && (
                  <div className="flex items-center gap-2 text-xs">
                    {doneCount > 0 && (
                      <span className="flex items-center gap-1 text-emerald-600">
                        <CheckCircle className="h-3 w-3" />{doneCount} done
                      </span>
                    )}
                    {errorCount > 0 && (
                      <span className="flex items-center gap-1 text-red-500">
                        <XCircle className="h-3 w-3" />{errorCount} failed
                      </span>
                    )}
                  </div>
                )}
              </div>
              {!uploading && (
                <button onClick={clearResults} className="text-muted-foreground hover:text-foreground transition-colors">
                  <X className="h-4 w-4" />
                </button>
              )}
            </div>

            {/* File list */}
            <div className="divide-y divide-border/40">
              {results.map((r, i) => (
                <div
                  key={i}
                  className={`flex items-center gap-3 px-4 py-3 transition-colors duration-200 ${
                    r.status === "uploading" ? "bg-primary/[0.02]" : ""
                  }`}
                >
                  {/* Status icon */}
                  <div className="shrink-0">
                    {r.status === "pending" && <div className="h-5 w-5 rounded-full border-2 border-muted-foreground/30" />}
                    {r.status === "uploading" && <Loader2 className="h-5 w-5 text-primary animate-spin" />}
                    {r.status === "done" && (
                      <div className="flex h-5 w-5 items-center justify-center rounded-full bg-emerald-100">
                        <CheckCircle className="h-3.5 w-3.5 text-emerald-600" />
                      </div>
                    )}
                    {r.status === "error" && (
                      <div className="flex h-5 w-5 items-center justify-center rounded-full bg-red-100">
                        <XCircle className="h-3.5 w-3.5 text-red-500" />
                      </div>
                    )}
                  </div>

                  {/* File info */}
                  <FileText className="h-4 w-4 text-muted-foreground shrink-0" />
                  <div className="flex-1 min-w-0">
                    <p className="text-sm font-medium text-foreground truncate">{r.file}</p>
                    {r.status === "done" && r.vendor && (
                      <p className="text-xs text-muted-foreground">{r.vendor}</p>
                    )}
                    {r.status === "error" && (
                      <p className="text-xs text-red-500">{r.error}</p>
                    )}
                  </div>

                  {/* Action */}
                  {r.status === "done" && r.invoiceId && (
                    <Link to={`/invoices/${r.invoiceId}`}>
                      <Button variant="ghost" size="sm" className="text-xs gap-1 text-primary hover:text-primary-dark">
                        View <ArrowRight className="h-3 w-3" />
                      </Button>
                    </Link>
                  )}
                </div>
              ))}
            </div>

            {/* Post-upload CTA */}
            {!uploading && doneCount > 0 && (
              <div className="px-4 py-3 border-t border-border/60 bg-muted/20">
                <Link to="/invoices">
                  <Button variant="outline" size="sm" className="gap-1 w-full sm:w-auto">
                    View All Invoices <ArrowRight className="h-3.5 w-3.5" />
                  </Button>
                </Link>
              </div>
            )}
          </CardContent>
        </Card>
      )}
    </div>
  );
}
