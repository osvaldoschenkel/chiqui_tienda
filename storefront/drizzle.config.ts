import { defineConfig } from "drizzle-kit";

export default defineConfig({
  out: "./drizzle",
  schema: "./db/commerce-schema.ts",
  dialect: "sqlite",
});
