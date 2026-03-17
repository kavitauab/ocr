import type { Request, Response, NextFunction } from "express";
import { getSessionFromRequest, type SessionUser } from "../lib/auth";

declare global {
  namespace Express {
    interface Request {
      user?: SessionUser;
    }
  }
}

export async function authMiddleware(req: Request, res: Response, next: NextFunction) {
  const user = await getSessionFromRequest(req);
  if (!user) {
    res.status(401).json({ error: "Unauthorized" });
    return;
  }
  req.user = user;
  next();
}
