/**
 * Extract every <script> block from a Blade view, neutralise Blade directives, and ask
 * node to PARSE it. Catches the class of bug that ships silently: broken JS inside a
 * <script> tag renders a page that simply stops working, and no PHP linter sees it.
 *
 * Usage: node jscheck.js <blade file> [...]
 */
const fs = require('fs');
const vm = require('vm');

let bad = 0;
for (const file of process.argv.slice(2)) {
    const src = fs.readFileSync(file, 'utf8');
    const blocks = [...src.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/gi)];
    if (!blocks.length) { console.log(`  – ${file}: no inline script blocks`); continue; }
    blocks.forEach((m, i) => {
        // Blade → placeholders that are valid JS.
        let js = m[1]
            .replace(/\{\{--[\s\S]*?--\}\}/g, '')
            .replace(/\{\{[^}]*\}\}/g, '0')
            .replace(/\{!![^}]*!!\}/g, '0')
            .replace(/@json\([^)]*\)/g, 'null')
            .replace(/^\s*@(if|elseif|else|endif|foreach|endforeach|php|endphp|isset|endisset|can|endcan)\b.*$/gm, '');
        // Count the line offset so a failure points at the real line in the file.
        const before = src.slice(0, m.index);
        const line0 = before.split('\n').length;
        try {
            new vm.Script(js, { filename: `${file} (block ${i + 1}, starts line ${line0})` });
            console.log(`  ✓ ${file} block ${i + 1} (line ${line0}) parses`);
        } catch (e) {
            bad++;
            console.log(`  ✗ ${file} block ${i + 1} (starts line ${line0}): ${e.message}`);
        }
    });
}
process.exit(bad ? 1 : 0);
