#!/usr/bin/env node
/**
 * Fix right_pl.html headings:
 * 1. Raise heading levels by 1 (h4→h3, h5→h4, h6→h5) from line 33 onwards
 * 2. Copy IDs from right.html to matching sections in right_pl.html (by section number)
 */
const fs = require('fs');
const DIR = __dirname;

// Read both files
const enLines = fs.readFileSync(DIR + '/right.html', 'utf8').split('\n');
const plLines = fs.readFileSync(DIR + '/right_pl.html', 'utf8').split('\n');

// Build a map: section number → id from right.html
const idMap = {};
const hRe = /<h([1-6])([^>]*)>([\s\S]*?)<\/h\1>/i;
for (const line of enLines) {
    const m = line.match(hRe);
    if (!m) continue;
    const attrs = m[2];
    const text = m[3].replace(/<[^>]+>/g, '');
    // Extract section number
    const numMatch = text.match(/^(\d+(?:\.\d+)*)/);
    if (!numMatch) continue;
    const num = numMatch[1];
    // Extract id
    const idMatch = attrs.match(/id="([^"]+)"/);
    if (!idMatch) continue;
    idMap[num] = idMatch[1];
}

console.log(`Found ${Object.keys(idMap).length} IDs in right.html`);

// Process right_pl.html
let changed = 0;
for (let i = 32; i < plLines.length; i++) { // line 33 = index 32
    const line = plLines[i];
    const m = line.match(hRe);
    if (!m) continue;

    const oldLevel = parseInt(m[1]);
    const attrs = m[2];
    const content = m[3];
    const newLevel = Math.max(1, oldLevel - 1); // raise by 1

    // Extract section number from content
    const text = content.replace(/<[^>]+>/g, '');
    const numMatch = text.match(/^(\d+(?:\.\d+)*)/);
    const sectionNum = numMatch ? numMatch[1] : null;

    // Build new attributes
    let newAttrs = attrs;
    // Remove old id if any
    newAttrs = newAttrs.replace(/\s*id="[^"]*"/, '');
    // Add id from EN version if available
    if (sectionNum && idMap[sectionNum]) {
        newAttrs = ` id="${idMap[sectionNum]}"` + newAttrs;
    }

    const newLine = `<h${newLevel}${newAttrs}>${content}</h${newLevel}>`;
    if (newLine !== line) {
        plLines[i] = newLine;
        changed++;
        if (oldLevel !== newLevel) {
            console.log(`  h${oldLevel}→h${newLevel}: ${(sectionNum || '?')} ${text.substring(0, 60)}`);
        }
    }
}

fs.writeFileSync(DIR + '/right_pl.html', plLines.join('\n'), 'utf8');
console.log(`\n${changed} headings updated in right_pl.html`);
