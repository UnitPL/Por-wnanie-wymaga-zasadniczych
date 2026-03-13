#!/usr/bin/env node
/**
 * Fix left_pl.html headings:
 * 1. Raise heading levels by 1 (h4→h3, h5→h4, h6→h5) from line 30 onwards
 * 2. Copy IDs from left.html to matching sections in left_pl.html (by section number)
 */
const fs = require('fs');
const DIR = __dirname;

// Read both files
const enLines = fs.readFileSync(DIR + '/left.html', 'utf8').split('\n');
const plLines = fs.readFileSync(DIR + '/left_pl.html', 'utf8').split('\n');

// Build a map: section number → {id, dataPair} from left.html
const idMap = {};
const hRe = /<h([1-6])([^>]*)>([\s\S]*?)<\/h\1>/i;
for (const line of enLines) {
    const m = line.match(hRe);
    if (!m) continue;
    const attrs = m[2];
    const text = m[3].replace(/<[^>]+>/g, '');
    const numMatch = text.match(/^(\d+(?:\.\d+)*)/);
    if (!numMatch) continue;
    const num = numMatch[1];
    const idMatch = attrs.match(/id="([^"]+)"/);
    const dpMatch = attrs.match(/data-pair="([^"]+)"/);
    idMap[num] = {
        id: idMatch ? idMatch[1] : null,
        dataPair: dpMatch ? dpMatch[1] : null
    };
}

console.log(`Found ${Object.keys(idMap).length} sections in left.html`);

// Process left_pl.html from line 30 onwards (index 29)
let changed = 0;
for (let i = 29; i < plLines.length; i++) {
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
        const en = idMap[sectionNum];
        if (en.id) {
            newAttrs = ` id="${en.id}"` + newAttrs;
        }
        if (en.dataPair && !newAttrs.includes('data-pair')) {
            newAttrs += ` data-pair="${en.dataPair}"`;
        }
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

fs.writeFileSync(DIR + '/left_pl.html', plLines.join('\n'), 'utf8');
console.log(`\n${changed} headings updated in left_pl.html`);
