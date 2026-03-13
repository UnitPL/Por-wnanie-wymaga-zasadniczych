const fs = require('fs');
const DIR = __dirname;
const target = process.argv[2];
if (!target) { console.error('Usage: node show-paras.js <section>'); process.exit(1); }

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
    current = { number, heading: text, content: '' };
    lastIdx = m.index + m[0].length;
  }
  if (current) { current.content = html.slice(lastIdx); sections.push(current); }
  return sections;
}

const left = fs.readFileSync(DIR + '/left.html', 'utf8');
const right = fs.readFileSync(DIR + '/right.html', 'utf8');
const ls = parseSections(left).find(s => s.number === target);
const rs = parseSections(right).find(s => s.number === target);
const lp = ls ? (ls.content.match(/<p[^>]*>[\s\S]*?<\/p>/gi) || []) : [];
const rp = rs ? (rs.content.match(/<p[^>]*>[\s\S]*?<\/p>/gi) || []) : [];
const max = Math.max(lp.length, rp.length);
for (let i = 0; i < max; i++) {
  const lt = (lp[i] || '').replace(/<[^>]+>/g, '').trim().substring(0, 100);
  const rt = (rp[i] || '').replace(/<[^>]+>/g, '').trim().substring(0, 100);
  console.log(i + ' L: ' + lt);
  console.log(i + ' R: ' + rt);
  console.log('---');
}
