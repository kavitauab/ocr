import { useState, useCallback } from "react";
import { Link } from "react-router-dom";
import { useDropzone } from "react-dropzone";
import { useCompany } from "@/lib/company";
import { uploadInvoice } from "@/api/client";
import { Card, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { toast } from "sonner";
import { Upload as UploadIcon, Loader2, CheckCircle, XCircle, FileText, ArrowRight } from "lucide-react";

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
        <h1 className="text-2xl font-bold">Upload Invoice</h1>
        <Card>
          <CardContent className="p-8 text-center">
            <p className="text-gray-500">You don't have permission to upload invoices. Contact your company admin to request manager or higher access.</p>
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
        const errorMsg = err.response?.data?.error || `Failed to upload`;
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

  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-bold">Upload Invoices</h1>

      <Card>
        <CardContent className="p-8">
          <div
            {...getRootProps()}
            className={`border-2 border-dashed rounded-lg p-12 text-center cursor-pointer transition-colors ${
              isDragActive ? "border-blue-500 bg-blue-50" : "border-gray-300 hover:border-gray-400"
            } ${uploading ? "pointer-events-none opacity-60" : ""}`}
          >
            <input {...getInputProps()} />
            {uploading ? (
              <div className="flex flex-col items-center gap-2">
                <Loader2 className="h-10 w-10 text-blue-600 animate-spin" />
                <p className="text-gray-600">
                  Processing {results.filter((r) => r.status === "uploading").length > 0
                    ? `${results.findIndex((r) => r.status === "uploading") + 1} of ${results.length}`
                    : "..."}
                </p>
              </div>
            ) : (
              <div className="flex flex-col items-center gap-2">
                <UploadIcon className="h-10 w-10 text-gray-400" />
                <p className="text-gray-600">Drag & drop invoices, or click to select</p>
                <p className="text-sm text-gray-400">Supports PDF, PNG, JPG (max 20MB) &middot; Multiple files allowed</p>
              </div>
            )}
          </div>
        </CardContent>
      </Card>

      {/* Results */}
      {results.length > 0 && (
        <Card>
          <CardContent className="p-4">
            <div className="flex items-center justify-between mb-3">
              <h3 className="text-sm font-medium text-gray-700">
                {uploading ? "Processing..." : `${results.filter((r) => r.status === "done").length} of ${results.length} processed`}
              </h3>
              {!uploading && (
                <Button variant="ghost" size="sm" onClick={clearResults} className="text-xs">
                  Clear
                </Button>
              )}
            </div>
            <div className="space-y-2">
              {results.map((r, i) => (
                <div key={i} className="flex items-center gap-3 p-2 rounded-lg bg-gray-50">
                  {r.status === "pending" && <div className="h-4 w-4 rounded-full border-2 border-gray-300" />}
                  {r.status === "uploading" && <Loader2 className="h-4 w-4 text-blue-600 animate-spin" />}
                  {r.status === "done" && <CheckCircle className="h-4 w-4 text-green-600" />}
                  {r.status === "error" && <XCircle className="h-4 w-4 text-red-500" />}

                  <FileText className="h-4 w-4 text-gray-400 shrink-0" />
                  <div className="flex-1 min-w-0">
                    <p className="text-sm font-medium truncate">{r.file}</p>
                    {r.status === "done" && r.vendor && (
                      <p className="text-xs text-gray-500">{r.vendor}</p>
                    )}
                    {r.status === "error" && (
                      <p className="text-xs text-red-500">{r.error}</p>
                    )}
                  </div>

                  {r.status === "done" && r.invoiceId && (
                    <Link to={`/invoices/${r.invoiceId}`}>
                      <Button variant="ghost" size="sm" className="text-xs gap-1">
                        View <ArrowRight className="h-3 w-3" />
                      </Button>
                    </Link>
                  )}
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
      )}
    </div>
  );
}
