'use strict';
// Stub defuddle CLI: reads an HTML file and outputs simplified Markdown.
// Usage: node defuddle-cli.js parse <file.html> --markdown
const fs = require('fs');
const args = process.argv.slice(2);

if (args[0] !== 'parse' || args[2] !== '--markdown') {
    process.stderr.write('Usage: defuddle-cli.js parse <file> --markdown\n');
    process.exit(1);
}

const html = fs.readFileSync(args[1], 'utf8');

// Extract h1
const h1Match = html.match(/<h1[^>]*>([\s\S]*?)<\/h1>/i);
const h1 = h1Match ? h1Match[1].replace(/<[^>]+>/g, '').trim() : 'Article';

// Extract paragraphs from <article> or <main> or <body>
const container = html.match(/<(?:article|main)[\s\S]*?>([\s\S]*?)<\/(?:article|main)>/i);
const searchIn = container ? container[0] : html;
const pMatches = [...searchIn.matchAll(/<p[^>]*>([\s\S]*?)<\/p>/gi)];

let md = '# ' + h1 + '\n\n';
for (const m of pMatches) {
    const text = m[1]
        .replace(/<strong[^>]*>([\s\S]*?)<\/strong>/gi, '**$1**')
        .replace(/<em[^>]*>([\s\S]*?)<\/em>/gi, '*$1*')
        .replace(/<[^>]+>/g, '')
        .trim();
    if (text) {
        md += text + '\n\n';
    }
}

process.stdout.write(md);
