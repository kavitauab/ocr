import "dotenv/config";
import express from "express";
import cors from "cors";
import path from "path";
import { authMiddleware } from "./middleware/auth";
import authRoutes from "./routes/auth";
import invoiceRoutes from "./routes/invoices";
import companyRoutes from "./routes/companies";
import userRoutes from "./routes/users";
import emailRoutes from "./routes/emails";
import settingsRoutes from "./routes/settings";
import cronRoutes from "./routes/cron";

const app = express();
const PORT = process.env.PORT || 3001;

app.use(cors());
app.use(express.json());

// Static file serving for uploads
const uploadsDir = process.env.UPLOAD_DIR || path.join(process.cwd(), "uploads");
app.use("/uploads", express.static(uploadsDir));

// Health check
app.get("/api/health", (_req, res) => {
  res.json({ status: "ok" });
});

// Public routes
app.use("/api/auth", authRoutes);
app.use("/api/cron", cronRoutes);

// Protected routes
app.use("/api/invoices", authMiddleware, invoiceRoutes);
app.use("/api/companies", authMiddleware, companyRoutes);
app.use("/api/users", authMiddleware, userRoutes);
app.use("/api/user", authMiddleware, userRoutes);
app.use("/api/emails", authMiddleware, emailRoutes);
app.use("/api/settings", authMiddleware, settingsRoutes);

app.listen(PORT, () => {
  console.log(`API server running on port ${PORT}`);
});

export default app;
