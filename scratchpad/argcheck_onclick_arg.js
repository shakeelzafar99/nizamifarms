/**
 * Proves flJsArg survives BOTH layers: the HTML attribute parser, then the JS parser.
 * The functions are pulled OUT OF THE BLADE ITSELF, so this cannot drift from what ships.
 */
const fs = require('fs');
const src = fs.readFileSync(
  'C:/NF App/nizamifarms/resources/views/pages/riders-map/partials/fleet.blade.php', 'utf8');

const grab = name => {
  const i = src.indexOf('function ' + name + '(');
  if (i < 0) throw new Error('missing ' + name);
  let depth = 0, started = false;
  for (let j = i; j < src.length; j++) {
    if (src[j] === '{') { depth++; started = true; }
    else if (src[j] === '}') { depth--; if (started && depth === 0) return src.slice(i, j + 1); }
  }
  throw new Error('unbalanced ' + name);
};

// eslint-disable-next-line no-eval
const ctx = eval('(function(){' + grab('flEsc') + grab('flJsArg') + 'return {flEsc, flJsArg};})()');

// What a browser does to an attribute value before handing it to the JS engine.
const htmlDecode = s => s
  .replace(/&lt;/g, '<').replace(/&gt;/g, '>')
  .replace(/&quot;/g, '"').replace(/&#039;/g, "'")
  .replace(/&amp;/g, '&');

const names = [
  'AY-4771',
  'Danish - own bike',
  'He said "go"',
  "O'Brien <van>",
  'back\\slash',
  '<img src=x onerror=alert(1)>',
  'A & B "C" \'D\'',
];

let bad = 0;
for (const name of names) {
  const attr = 'flvOpenSchedule(4,' + ctx.flJsArg(name) + ')';
  // The attribute must not contain a raw quote — that is what broke it.
  if (/"/.test(attr)) { console.log('RAW QUOTE LEAKED for', JSON.stringify(name)); bad++; continue; }
  let got = null;
  const flvOpenSchedule = (id, n) => { got = n; };
  try {
    // eslint-disable-next-line no-eval
    eval(htmlDecode(attr));
  } catch (e) {
    console.log('PARSE FAIL', JSON.stringify(name), e.message); bad++; continue;
  }
  const ok = got === name;
  if (!ok) bad++;
  console.log((ok ? 'ok   ' : 'BAD  ') + JSON.stringify(name) + ' -> ' + JSON.stringify(got));
}
console.log(bad ? bad + ' FAILURES' : 'all round-trip exactly, no raw quotes');
process.exit(bad ? 1 : 0);
