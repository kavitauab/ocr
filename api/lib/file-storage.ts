import { writeFile, readFile, mkdir } from "fs/promises";
import { existsSync } from "fs";
import path from "path";
import { nanoid } from "nanoid";

const UPLOADS_DIR = process.env.UPLOAD_DIR || path.join(process.cwd(), "uploads");

async function ensureDir(dir: string) {
  if (!existsSync(dir)) {
    await mkdir(dir, { recursive: true });
  }
}

export async function saveFile(
  buffer: Buffer,
  originalName: string,
  companyId?: string | null
): Promise<{ storedFilename: string; fileType: string }> {
  const ext = path.extname(originalName).toLowerCase().replace(".", "");
  const filename = `${nanoid()}.${ext}`;

  const subdir = companyId
    ? path.join(UPLOADS_DIR, companyId)
    : UPLOADS_DIR;
  await ensureDir(subdir);

  const storedFilename = companyId ? `${companyId}/${filename}` : filename;
  const filePath = path.join(UPLOADS_DIR, storedFilename);

  await writeFile(filePath, buffer);

  return { storedFilename, fileType: ext };
}

export function getFilePath(storedFilename: string): string {
  return path.join(UPLOADS_DIR, storedFilename);
}

export async function readStoredFile(storedFilename: string): Promise<Buffer> {
  const filePath = getFilePath(storedFilename);
  return readFile(filePath);
}
