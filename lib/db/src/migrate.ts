import path from "node:path";
import { migrate } from "drizzle-orm/node-postgres/migrator";
import { db } from "./index";

export async function migrateDatabase(
  migrationsFolder = path.resolve(process.cwd(), "lib/db/drizzle"),
): Promise<void> {
  await migrate(db, { migrationsFolder });
}