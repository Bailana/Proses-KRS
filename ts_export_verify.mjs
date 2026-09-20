import { setTimeout as wait } from "node:timers/promises";
const B = "http://127.0.0.1:8000/api/krs";

async function run(label, url, tableUrl, checkTableTotal) {
  const r = await fetch(url);
  const d = await r.json();
  const t0 = Date.now();
  let firstProgressAt = null;
  while (true) {
    await wait(2500);
    const s = await (await fetch(`${B}/export/status/${d.download_token}`)).json();
    const el = Math.round((Date.now() - t0) / 1000);
    if ((s.progress > 0 || (s.processed_rows ?? 0) > 0) && firstProgressAt === null) firstProgressAt = el;
    if (el % 10 < 3) console.log(`  [${el}s] ${s.status} ${s.progress}% rows=${(s.processed_rows ?? 0).toLocaleString()}/${(s.total_rows ?? 0).toLocaleString()}`);
    if (s.status === "completed") {
      let line = `DONE in ${el}s rows=${(s.processed_rows).toLocaleString()} total=${(s.total_rows).toLocaleString()} size=${((s.file_size ?? 0) / 1048576).toFixed(2)}MB firstProgress=${firstProgressAt ?? "-"}s`;
      if (checkTableTotal) {
        const table = await (await fetch(tableUrl)).json();
        line += ` | table total=${table.total.toLocaleString()} MATCH: ${s.processed_rows === table.total ? "[OK]" : "[FAIL]"}`;
      }
      console.log(line);
      return s;
    }
    if (s.status === "failed") { console.log("FAILED:", JSON.stringify(s)); return s; }
    if (el > 120) { console.log("TIMEOUT"); return null; }
  }
}

console.log("=== CASE 1: search NIM matching rows (should progress + match table) ===");
// Find a real NIM that exists
const probe = await (await fetch(`${B}?search_nim=1&per_page=1`)).json();
const someNim = probe.data?.[0]?.student?.nim;
console.log("using NIM:", someNim);
if (someNim) await run("nim", `${B}/export/init?search_nim=${encodeURIComponent(someNim)}`, `${B}?search_nim=${encodeURIComponent(someNim)}&per_page=1`, true);

console.log("\n=== CASE 2: empty search (NIM=ts → 0 rows, should complete fast at 0) ===");
await run("empty", `${B}/export/init?search_nim=ts`, null, false);
