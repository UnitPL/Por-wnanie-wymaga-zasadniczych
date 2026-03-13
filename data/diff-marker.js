#!/usr/bin/env node
/**
 * Word-level diff marker for annex-diff HTML files.
 * Usage: node diff-marker.js <section> [--dry-run]
 * Example: node diff-marker.js 1.1.7 --dry-run
 */
const fs = require('fs');
const DIR = __dirname;

// ===== SECTION PARSER =====
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
    current = { number, heading: text, headingHtml: m[0], content: '', contentStart: 0 };
    lastIdx = m.index + m[0].length;
    current.contentStart = lastIdx;
    current.startIdx = m.index;
  }
  if (current) { current.content = html.slice(lastIdx); sections.push(current); }
  return sections;
}

// ===== SPLIT PARAGRAPHS (with positions relative to section content) =====
function splitParas(html) {
  const result = [];
  const regex = /<p[^>]*>[\s\S]*?<\/p>/gi;
  let m;
  while ((m = regex.exec(html)) !== null) {
    result.push({ html: m[0], start: m.index, end: m.index + m[0].length });
  }
  return result;
}

// ===== TOKENIZE HTML into words with mark-awareness =====
// Returns array of { word, marked, pos, len } where pos/len refer to positions in html
function tokenizeWords(html) {
  const words = [];
  let i = 0;
  let markDepth = 0;

  while (i < html.length) {
    // HTML tag?
    if (html[i] === '<') {
      const tagMatch = html.slice(i).match(/^<(\/?)mark([^>]*)>/i);
      if (tagMatch) {
        if (tagMatch[1]) markDepth = Math.max(0, markDepth - 1);
        else markDepth++;
      }
      const end = html.indexOf('>', i);
      if (end === -1) break;
      i = end + 1;
      continue;
    }

    // Whitespace?
    if (/\s/.test(html[i])) { i++; continue; }

    // Word - collect characters until whitespace or tag
    const wordStart = i;
    while (i < html.length && html[i] !== '<' && !/\s/.test(html[i])) i++;
    const wordText = html.slice(wordStart, i);
    words.push({ word: wordText, marked: markDepth > 0, pos: wordStart, len: i - wordStart });
  }

  return words;
}

// ===== PLAIN TEXT from HTML =====
function toText(html) {
  return html.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim();
}

// ===== NORMALIZE word for comparison (ignore trailing . vs ;) =====
function normWord(w) {
  return w.toLowerCase().replace(/[\u2018\u2019\u201A\u201B]/g, "'").replace(/[\u2013\u2014]/g, '-').replace(/[)]*[.;,:!?]*$/, '');
}

// ===== LCS DIFF =====
function lcsCommon(a, b) {
  const m = a.length, n = b.length;
  if (m * n > 500000) return { cA: new Set(), cB: new Set() };
  const dp = [];
  for (let i = 0; i <= m; i++) dp[i] = new Uint16Array(n + 1);
  for (let i = 1; i <= m; i++)
    for (let j = 1; j <= n; j++)
      dp[i][j] = normWord(a[i-1]) === normWord(b[j-1])
        ? dp[i-1][j-1] + 1
        : Math.max(dp[i-1][j], dp[i][j-1]);
  const cA = new Set(), cB = new Set();
  let i = m, j = n;
  while (i > 0 && j > 0) {
    if (normWord(a[i-1]) === normWord(b[j-1])) { cA.add(--i); cB.add(--j); }
    else if (dp[i-1][j] >= dp[i][j-1]) i--;
    else j--;
  }
  return { cA, cB };
}

// ===== GROUP CONSECUTIVE CHANGED WORD INDICES =====
function groupSpans(tokens, changedSet) {
  const spans = [];
  let start = -1;
  for (let i = 0; i <= tokens.length; i++) {
    if (changedSet.has(i)) {
      if (start === -1) start = i;
    } else {
      if (start !== -1) {
        spans.push({ from: start, to: i });
        start = -1;
      }
    }
  }
  return spans;
}

// ===== STRIP MARKS AND BUILD POSITION MAP =====
// Returns cleanHtml (no mark tags) and map: cleanPos -> origPos
function stripMarksWithMap(html) {
  const map = [];
  let clean = '';
  let i = 0;
  while (i < html.length) {
    const tagMatch = html.slice(i).match(/^<\/?mark[^>]*>/i);
    if (tagMatch) {
      i += tagMatch[0].length;
      continue;
    }
    map[clean.length] = i;
    clean += html[i];
    i++;
  }
  map[clean.length] = i;
  return { cleanHtml: clean, map };
}

// ===== BUILD MARK-DEPTH AT EACH POSITION IN ORIGINAL HTML =====
function buildMarkDepths(html) {
  const depths = new Uint8Array(html.length + 1);
  let depth = 0, i = 0;
  while (i < html.length) {
    if (html[i] === '<') {
      const tag = html.slice(i).match(/^<(\/?)mark([^>]*)>/i);
      if (tag) {
        if (tag[1]) depth = Math.max(0, depth - 1);
        else depth++;
      }
      const end = html.indexOf('>', i);
      if (end === -1) break;
      i = end + 1;
      continue;
    }
    depths[i] = depth;
    i++;
  }
  return depths;
}

// ===== APPLY MARKS TO PARAGRAPH =====
function markParagraph(paraHtml, side) {
  // side: 'left' or 'right'
  return function(otherParaHtml) {
    // Strip marks for symmetric tokenization
    const { cleanHtml: myClean, map: myMap } = stripMarksWithMap(paraHtml);
    const { cleanHtml: otherClean } = stripMarksWithMap(otherParaHtml);
    const markDepths = buildMarkDepths(paraHtml);

    // Tokenize clean versions (no mark-induced splits)
    const cleanTokens = tokenizeWords(myClean);
    const otherTokens = tokenizeWords(otherClean);

    const myWords = cleanTokens.map(t => t.word);
    const otherWords = otherTokens.map(t => t.word);

    const { cA, cB } = lcsCommon(myWords, otherWords);
    const myChanged = new Set();
    for (let j = 0; j < myWords.length; j++) if (!cA.has(j)) myChanged.add(j);

    const spans = groupSpans(cleanTokens, myChanged);

    // Check if a clean token is already marked in original HTML
    function isMarked(tokenIdx) {
      const origPos = myMap[cleanTokens[tokenIdx].pos];
      return markDepths[origPos] > 0;
    }

    // Filter: skip spans where ALL words are already marked
    const unmarkedSpans = spans.filter(s => {
      for (let i = s.from; i < s.to; i++) {
        if (!isMarked(i)) return true;
      }
      return false;
    });

    // Collect all mark operations (positions in ORIGINAL html via map)
    const ops = [];
    const changes = [];

    // 1) Main diff marks
    for (const span of unmarkedSpans) {
      let groups = [];
      let grpStart = -1;
      for (let i = span.from; i <= span.to; i++) {
        const unmarked = i < span.to && !isMarked(i);
        if (unmarked) {
          if (grpStart === -1) grpStart = i;
        } else {
          if (grpStart !== -1) {
            groups.push({ from: grpStart, to: i });
            grpStart = -1;
          }
        }
      }
      for (const g of groups) {
        const startPos = myMap[cleanTokens[g.from].pos];
        const endClean = cleanTokens[g.to - 1].pos + cleanTokens[g.to - 1].len;
        const endPos = myMap[endClean];
        const wordCount = g.to - g.from;
        let cls;
        if (side === 'left') cls = wordCount >= 8 ? 'del-major' : 'del-minor';
        else cls = wordCount >= 8 ? 'major' : 'minor';
        ops.push({ pos: startPos, end: endPos, cls });
        changes.push({ side, cls, text: cleanTokens.slice(g.from, g.to).map(t => t.word).join(' ') });
      }
    }

    // 2) Punctuation-only differences (matched words that differ only in trailing punct)
    const myCommon = [...cA].sort((a, b) => a - b);
    const otherCommon = [...cB].sort((a, b) => a - b);
    for (let k = 0; k < myCommon.length; k++) {
      const mi = myCommon[k];
      const oi = otherCommon[k];
      const myWord = cleanTokens[mi].word;
      const otherWord = otherTokens[oi].word;
      if (myWord === otherWord) continue;
      if (isMarked(mi)) continue;
      if (normWord(myWord) !== normWord(otherWord)) continue;
      const myTrail = myWord.match(/[.;,:!?]$/);
      if (myTrail) {
        const otherTrail = otherWord.match(/[.;,:!?]$/);
        if (!otherTrail || myTrail[0] !== otherTrail[0]) {
          const cleanPunctPos = cleanTokens[mi].pos + cleanTokens[mi].len - 1;
          const punctPos = myMap[cleanPunctPos];
          const cls = side === 'left' ? 'del-minor' : 'minor';
          ops.push({ pos: punctPos, end: punctPos + 1, cls });
          changes.push({ side, cls, text: myTrail[0] });
        }
      }
    }

    if (ops.length === 0) return { html: paraHtml, changes: [] };

    // Sort operations by position descending (apply from end to preserve positions)
    ops.sort((a, b) => b.pos - a.pos);

    let result = paraHtml;
    for (const op of ops) {
      const spanText = result.slice(op.pos, op.end);
      result = result.slice(0, op.pos) + `<mark class="${op.cls}" data-diff>` + spanText + '</mark>' + result.slice(op.end);
    }

    return { html: result, changes };
  };
}

// ===== PROCESS SECTION =====
function processSection(leftSec, rightSec) {
  const leftParas = splitParas(leftSec.content);
  const rightParas = splitParas(rightSec.content);
  const n = Math.min(leftParas.length, rightParas.length);

  const allChanges = [];
  const newLeftParas = [];
  const newRightParas = [];

  for (let i = 0; i < n; i++) {
    const lp = leftParas[i].html;
    const rp = rightParas[i].html;

    // Check if plain texts differ
    const lt = toText(lp);
    const rt = toText(rp);

    if (lt === rt) {
      newLeftParas.push(lp);
      newRightParas.push(rp);
      continue;
    }

    // If one side is empty (spacer <p></p>), treat the other as entirely new
    if (!lt.trim()) {
      const clean = rp.replace(/<mark[^>]*>([\s\S]*?)<\/mark>/gi, '$1');
      const marked = clean.replace(/(<p[^>]*>)([\s\S]*?)(<\/p>)/i, '$1<mark class="major" data-diff>$2</mark>$3');
      newLeftParas.push(lp);
      newRightParas.push(marked);
      allChanges.push({ side: 'right', cls: 'major', text: rt });
      continue;
    }
    if (!rt.trim()) {
      const clean = lp.replace(/<mark[^>]*>([\s\S]*?)<\/mark>/gi, '$1');
      const marked = clean.replace(/(<p[^>]*>)([\s\S]*?)(<\/p>)/i, '$1<mark class="del-major" data-diff>$2</mark>$3');
      newLeftParas.push(marked);
      newRightParas.push(rp);
      allChanges.push({ side: 'left', cls: 'del-major', text: lt });
      continue;
    }

    // Process left side
    const leftResult = markParagraph(lp, 'left')(rp);
    // Process right side
    const rightResult = markParagraph(rp, 'right')(lp);

    newLeftParas.push(leftResult.html);
    newRightParas.push(rightResult.html);
    allChanges.push(...leftResult.changes, ...rightResult.changes);
  }

  // Handle extra paragraphs (no counterpart) — mark entire content as major
  // Strip any existing inner marks first, then wrap in single major mark
  function stripAllMarks(html) {
    return html.replace(/<mark[^>]*>([\s\S]*?)<\/mark>/gi, '$1');
  }
  for (let i = n; i < leftParas.length; i++) {
    const clean = stripAllMarks(leftParas[i].html);
    const marked = clean.replace(/(<p[^>]*>)([\s\S]*?)(<\/p>)/i, '$1<mark class="del-major" data-diff>$2</mark>$3');
    newLeftParas.push(marked);
    allChanges.push({ side: 'left', cls: 'del-major', text: toText(leftParas[i].html) });
  }
  for (let i = n; i < rightParas.length; i++) {
    const clean = stripAllMarks(rightParas[i].html);
    const marked = clean.replace(/(<p[^>]*>)([\s\S]*?)(<\/p>)/i, '$1<mark class="major" data-diff>$2</mark>$3');
    newRightParas.push(marked);
    allChanges.push({ side: 'right', cls: 'major', text: toText(rightParas[i].html) });
  }

  return { leftParas: newLeftParas, rightParas: newRightParas, origLeft: leftParas, origRight: rightParas, changes: allChanges };
}

// ===== REPLACE PARAGRAPHS IN FILE HTML =====
function applyToFile(fileHtml, section, origParas, newParas) {
  let result = fileHtml;
  for (let i = origParas.length - 1; i >= 0; i--) {
    if (i < newParas.length && origParas[i].html !== newParas[i]) {
      const absStart = section.contentStart + origParas[i].start;
      const absEnd = section.contentStart + origParas[i].end;
      result = result.slice(0, absStart) + newParas[i] + result.slice(absEnd);
    }
  }
  return result;
}

// ===== STRIP DIFF-MARKER MARKS FROM SECTION =====
// Removes <mark class="minor|major|del-minor|del-major">...</mark> but preserves inner content
function stripDiffMarks(html) {
  return html.replace(/<mark class="(?:minor|major|del-minor|del-major)" data-diff>([\s\S]*?)<\/mark>/gi, '$1');
}

function undoSection(fileHtml, section) {
  const cleaned = stripDiffMarks(section.content);
  if (cleaned === section.content) return fileHtml;
  const start = section.contentStart;
  const end = start + section.content.length;
  return fileHtml.slice(0, start) + cleaned + fileHtml.slice(end);
}

// ===== MAIN =====
// Parse args: first non-flag argument is the section, rest are flags
const args = process.argv.slice(2);
const flags = args.filter(a => a.startsWith('--'));
const positional = args.filter(a => !a.startsWith('--'));
const targetSection = positional[0];
if (!targetSection) { console.error('Usage: node diff-marker.js <section> [leftFile] [rightFile] [--dry-run] [--undo] [--redo]'); process.exit(1); }
const dryRun = flags.includes('--dry-run');
const undoMode = flags.includes('--undo');

const leftFile = positional[1] || 'left.html';
const rightFile = positional[2] || 'right.html';
const leftPath = DIR + '/' + leftFile;
const rightPath = DIR + '/' + rightFile;
let leftHtml = fs.readFileSync(leftPath, 'utf8');
let rightHtml = fs.readFileSync(rightPath, 'utf8');

const leftSecs = parseSections(leftHtml);
const rightSecs = parseSections(rightHtml);

const leftSec = leftSecs.find(s => s.number === targetSection);
const rightSec = rightSecs.find(s => s.number === targetSection);

// Handle one-sided sections (exists only on one side)
if (!leftSec && !rightSec) { console.error(`Section "${targetSection}" not found in either file`); process.exit(1); }

if (!leftSec || !rightSec) {
  const side = leftSec ? 'left' : 'right';
  const sec = leftSec || rightSec;
  const file = leftSec ? leftFile : rightFile;
  const cls = leftSec ? 'del-major' : 'major';
  const paras = splitParas(sec.content);

  console.log(`\n=== Section ${targetSection} (only in ${file}) ===`);
  console.log(`"${sec.heading}" (${paras.length} paras)\n`);

  if (paras.length === 0) { console.log('No paragraphs to mark.\n'); process.exit(0); }

  const newParas = paras.map(p => {
    const clean = p.html.replace(/<mark[^>]*>([\s\S]*?)<\/mark>/gi, '$1');
    const marked = clean.replace(/(<p[^>]*>)([\s\S]*?)(<\/p>)/i, `$1<mark class="${cls}" data-diff>$2</mark>$3`);
    return marked;
  });

  console.log(`  Marking ${paras.length} paragraphs as ${cls}`);
  console.log(`\n${paras.length} changes found.`);

  if (dryRun) {
    console.log('\n[DRY RUN] No files modified.\n');
  } else {
    let fileHtml = leftSec ? leftHtml : rightHtml;
    const filePath = leftSec ? leftPath : rightPath;
    fileHtml = applyToFile(fileHtml, sec, paras, newParas);
    fs.writeFileSync(filePath, fileHtml, 'utf8');
    console.log('\nFile updated.\n');
  }
  process.exit(0);
}

// Undo previous marks if --undo or --redo
if (undoMode || flags.includes('--redo')) {
  leftHtml = undoSection(leftHtml, leftSec);
  rightHtml = undoSection(rightHtml, rightSec);
  // Re-parse after undo
  const leftSecs2 = parseSections(leftHtml);
  const rightSecs2 = parseSections(rightHtml);
  Object.assign(leftSec, leftSecs2.find(s => s.number === targetSection));
  Object.assign(rightSec, rightSecs2.find(s => s.number === targetSection));
  if (undoMode) {
    fs.writeFileSync(leftPath, leftHtml, 'utf8');
    fs.writeFileSync(rightPath, rightHtml, 'utf8');
    console.log(`\nSection ${targetSection} marks stripped.\n`);
    process.exit(0);
  }
  console.log('[REDO] Previous marks stripped, re-processing...\n');
}

console.log(`\n=== Section ${targetSection} ===`);
console.log(`Left:  "${leftSec.heading}" (${splitParas(leftSec.content).length} paras)`);
console.log(`Right: "${rightSec.heading}" (${splitParas(rightSec.content).length} paras)\n`);

const result = processSection(leftSec, rightSec);

// Print changes
for (const c of result.changes) {
  const arrow = c.side === 'left' ? 'DEL' : 'INS';
  console.log(`  [${arrow}] (${c.cls}) "${c.text}"`);
}
console.log(`\n${result.changes.length} changes found.`);

if (dryRun) {
  console.log('\n[DRY RUN] No files modified.\n');
} else {
  leftHtml = applyToFile(leftHtml, leftSec, result.origLeft, result.leftParas);
  rightHtml = applyToFile(rightHtml, rightSec, result.origRight, result.rightParas);
  fs.writeFileSync(leftPath, leftHtml, 'utf8');
  fs.writeFileSync(rightPath, rightHtml, 'utf8');
  console.log('\nFiles updated.\n');
}
