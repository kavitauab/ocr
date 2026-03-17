import { useState, useCallback } from "react";
import { useNavigate } from "react-router-dom";
import { useDropzone } from "react-dropzone";
import { useCompany } from "@/lib/company";
import { uploadInvoice } from "@/api/client";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { toast } from "sonner";
import { Upload as UploadIcon, FileText, Loader2 } from "lucide-react";

export default function Upload() {
  const { selectedCompany } = useCompany();
  const navigate = useNavigate();
  const [uploading, setUploading] = useState(false);

  const onDrop = useCallback(async (files: File[]) => {
    if (!selectedCompany) {
      toast.error("Please select a company first");
      return;
    }
    setUploading(true);
    for (const file of files) {
      try {
        const result: any = await uploadInvoice(file, selectedCompany.id);
        toast.success(`Uploaded: ${file.name}`);
        if (result.invoice?.id) {
          navigate(`/invoices/${result.invoice.id}`);
          return;
        }
      } catch (err: any) {
        toast.error(err.response?.data?.error || `Failed to upload ${file.name}`);
      }
    }
    setUploading(false);
  }, [selectedCompany, navigate]);

  const { getRootProps, getInputProps, isDragActive } = useDropzone({
    onDrop,
    accept: { "application/pdf": [".pdf"], "image/png": [".png"], "image/jpeg": [".jpg", ".jpeg"] },
    maxSize: 20 * 1024 * 1024,
    disabled: uploading,
  });

  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-bold">Upload Invoice</h1>
      <Card>
        <CardContent className="p-8">
          <div
            {...getRootProps()}
            className={`border-2 border-dashed rounded-lg p-12 text-center cursor-pointer transition-colors ${
              isDragActive ? "border-blue-500 bg-blue-50" : "border-gray-300 hover:border-gray-400"
            }`}
          >
            <input {...getInputProps()} />
            {uploading ? (
              <div className="flex flex-col items-center gap-2">
                <Loader2 className="h-10 w-10 text-blue-600 animate-spin" />
                <p className="text-gray-600">Processing invoice...</p>
              </div>
            ) : (
              <div className="flex flex-col items-center gap-2">
                <UploadIcon className="h-10 w-10 text-gray-400" />
                <p className="text-gray-600">Drag & drop an invoice, or click to select</p>
                <p className="text-sm text-gray-400">Supports PDF, PNG, JPG (max 20MB)</p>
              </div>
            )}
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
