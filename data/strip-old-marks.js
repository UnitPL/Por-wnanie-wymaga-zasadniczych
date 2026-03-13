#!/usr/bin/env node
/**
 * Strip old diff-marker marks (without data-diff attribute) from a section.
 * Usage: node strip-old-marks.js <section> [section2] ...
 */
const fs = require('fs');
const DIR = __dirname;

function parseSections(html) {
  const sections = [];
  const regex = /<h([1-6])([^>]*)>([\s\S]*?)<\/h\1>/gi;
  let lastIdx = 0, current = null, m;
  while ((m = regex.exec(html)) !== null) {
    const content = html.slice(lastIdx, m.index);
    if (current) { current.content = content; sections.push(current); }
    const attrs = m[2], text = m[3].replace(/<[^>]+>/g, '');
    let number = null;
    const dp = attrs.match(/data-pair="([^"]+)"/);
    if (dp) number = dp[1];
    else { const nm = text.match(/^(\d+(?:\.\d+)*)/); if (nm) number = nm[1]; }
    current = { number, heading: text, content: '', contentStart: 0 };
    lastIdx = m.index + m[0].length;
    current.contentStart = lastIdx;
  }
  if (current) { current.content = html.slice(lastIdx); sections.push(current); }
  return sections;
}

const args = process.argv.slice(2);
// Separate file arguments (contain .html) from section targets
const files = args.filter(a => a.endsWith('.html'));
const targets = args.filter(a => !a.endsWith('.html'));
if (targets.length === 0) { console.error('Usage: node strip-old-marks.js [leftFile] [rightFile] <section> [section2] ...'); process.exit(1); }
const fileList = files.length > 0 ? files : ['left.html', 'right.html'];

for (const file of fileList) {
  let html = fs.readFileSync(DIR + '/' + file, 'utf8');
  let changed = false;
  for (const target of targets) {
    // Re-parse each time since positions shift after edits
    const secs = parseSections(html);
    const sec = secs.find(s => s.number === target);
    if (!sec) { console.log(file + ': section ' + target + ' not found'); continue; }
    // Strip all mark tags (with or without data-diff attribute)
    const cleaned = sec.content.replace(/<mark class="(?:minor|major|del-minor|del-major)"(?: data-diff)?>([\s\S]*?)<\/mark>/gi, '$1');
    if (cleaned !== sec.content) {
      const count = (sec.content.match(/<mark class="(?:minor|major|del-minor|del-major)"(?: data-diff)?>/gi) || []).length;
      html = html.slice(0, sec.contentStart) + cleaned + html.slice(sec.contentStart + sec.content.length);
      console.log(file + ': section ' + target + ' — stripped ' + count + ' old marks');
      changed = true;
    } else {
      console.log(file + ': section ' + target + ' — no old marks');
    }
  }
  if (changed) fs.writeFileSync(DIR + '/' + file, html, 'utf8');
}
console.log('Done.');
