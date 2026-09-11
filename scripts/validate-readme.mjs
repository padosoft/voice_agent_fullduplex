import { readFile, stat } from 'node:fs/promises';
import { dirname, extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const readmePath = resolve(root, 'README.md');
const markdown = await readFile(readmePath, 'utf8');
const failures = [];
const images = [];

for (const match of markdown.matchAll(/!\[([^\]]*)\]\(([^)\s]+)(?:\s+["'][^)]*)?\)/g)) {
  images.push({ alt: match[1].trim(), source: match[2], syntax: 'Markdown' });
}

for (const match of markdown.matchAll(/<img\b([^>]*)>/gi)) {
  const attributes = match[1];
  const source = attributes.match(/\bsrc=["']([^"']+)["']/i)?.[1];
  const alt = attributes.match(/\balt=["']([^"']*)["']/i)?.[1]?.trim();

  if (source) {
    images.push({ alt: alt ?? '', source, syntax: 'HTML' });
  }
}

if (images.length === 0) {
  failures.push('README.md does not contain any visual assets.');
}

for (const image of images) {
  if (!image.alt) {
    failures.push(`${image.syntax} image ${image.source} has no useful alt text.`);
  }

  if (/^(?:https?:|data:|#)/i.test(image.source)) {
    continue;
  }

  const assetPath = resolve(root, decodeURIComponent(image.source));

  if (!assetPath.startsWith(`${root}/`)) {
    failures.push(`Image path escapes the repository: ${image.source}`);
    continue;
  }

  try {
    const metadata = await stat(assetPath);

    if (!metadata.isFile() || metadata.size === 0) {
      failures.push(`Image is missing or empty: ${image.source}`);
      continue;
    }
  } catch {
    failures.push(`Image does not exist: ${image.source}`);
    continue;
  }

  const extension = extname(assetPath).toLowerCase();
  const contents = await readFile(assetPath);

  if (extension === '.png') {
    const signature = '89504e470d0a1a0a';

    if (contents.length < 24 || contents.subarray(0, 8).toString('hex') !== signature) {
      failures.push(`Invalid PNG file: ${image.source}`);
      continue;
    }

    const width = contents.readUInt32BE(16);
    const height = contents.readUInt32BE(20);

    if (width < 320 || height < 180) {
      failures.push(`README raster is too small for a useful preview: ${image.source} (${width}x${height}).`);
    }
  }

  if (extension === '.svg') {
    const svg = contents.toString('utf8');

    if (!/<svg\b/i.test(svg) || !/\bviewBox=["'][^"']+["']/i.test(svg)) {
      failures.push(`SVG lacks an svg root or viewBox: ${image.source}`);
    }

    if (!/<title\b/i.test(svg) || !/<desc\b/i.test(svg)) {
      failures.push(`SVG lacks accessible title/description content: ${image.source}`);
    }
  }
}

if (!markdown.includes('## Handoff')) {
  failures.push('README.md is missing the required Handoff section.');
}

if (failures.length > 0) {
  console.error('README validation failed:');

  for (const failure of failures) {
    console.error(`- ${failure}`);
  }

  process.exitCode = 1;
} else {
  console.log(`README validation passed (${images.length} local visual assets checked).`);
}
