/*
 * Reads the vertical metrics out of a WOFF2 file and prints them as JSON.
 *
 * WHY THIS IS JAVASCRIPT
 *
 * The metrics live in the head, hhea and OS/2 tables, which WOFF2 stores
 * inside a single Brotli stream. PHP has no Brotli extension here, and Node
 * has had zlib.brotliDecompressSync built in since v11 — no package, no
 * install. Node is already a hard requirement of this project: the stylesheet
 * these metrics are checked against is built by Vite.
 *
 * The alternative was to record the numbers in a JSON file beside the fonts
 * and have the test compare against that, which is exactly the mistake being
 * corrected — Bodoni's ascent-override was wrong for seven tasks precisely
 * because it was a number someone had written down rather than one anybody
 * had read out of the binary.
 *
 * Usage: node font-metrics.mjs <file.woff2> [...]
 */

import { readFileSync } from 'node:fs'
import { brotliDecompressSync } from 'node:zlib'

/* The 63 tags WOFF2 can refer to by index instead of by name, in order. */
const KNOWN_TAGS = [
  'cmap', 'head', 'hhea', 'hmtx', 'maxp', 'name', 'OS/2', 'post', 'cvt ', 'fpgm',
  'glyf', 'loca', 'prep', 'CFF ', 'VORG', 'EBDT', 'EBLC', 'gasp', 'hdmx', 'kern',
  'LTSH', 'PCLT', 'VDMX', 'vhea', 'vmtx', 'BASE', 'GDEF', 'GPOS', 'GSUB', 'EBSC',
  'JSTF', 'MATH', 'CBDT', 'CBLC', 'COLR', 'CPAL', 'SVG ', 'sbix', 'acnt', 'avar',
  'bdat', 'bloc', 'bsln', 'cvar', 'fdsc', 'feat', 'fmtx', 'fvar', 'gvar', 'hsty',
  'just', 'lcar', 'mort', 'morx', 'opbd', 'prop', 'trak', 'Zapf', 'Silf', 'Glat',
  'Gloc', 'Feat', 'Sill',
]

/* WOFF2 packs lengths as a base-128 varint, most significant group first. */
function readBase128(buffer, offset) {
  let value = 0

  for (let i = 0; i < 5; i++) {
    const byte = buffer[offset++]
    value = (value << 7) | (byte & 0x7f)

    if ((byte & 0x80) === 0) {
      return [value, offset]
    }
  }

  throw new Error('malformed UIntBase128')
}

/* A plain, uncompressed sfnt: TTF, OTF, or one font out of a TTC. */
function sfntTables(buffer, offset = 0) {
  const numTables = buffer.readUInt16BE(offset + 4)
  const out = {}

  for (let i = 0; i < numTables; i++) {
    const at = offset + 12 + i * 16
    const tag = buffer.toString('latin1', at, at + 4)
    const start = buffer.readUInt32BE(at + 8)
    const length = buffer.readUInt32BE(at + 12)

    out[tag] = buffer.subarray(start, start + length)
  }

  return out
}

function tables(file) {
  const buffer = readFileSync(file)
  const signature = buffer.toString('latin1', 0, 4)

  /*
   * The system fallback faces are read too, so the size-adjust ratio is
   * measured on both sides rather than on one. They are ordinary uncompressed
   * fonts, and Menlo ships as a collection.
   */
  if (signature === 'ttcf') {
    return sfntTables(buffer, buffer.readUInt32BE(12))
  }

  if (signature === '\u0000\u0001\u0000\u0000' || signature === 'true' || signature === 'OTTO') {
    return sfntTables(buffer)
  }

  if (signature !== 'wOF2') {
    throw new Error(`${file} is not a font this reader understands`)
  }

  const numTables = buffer.readUInt16BE(12)

  let at = 48
  const directory = []

  for (let i = 0; i < numTables; i++) {
    const flags = buffer[at++]
    const index = flags & 0x3f

    let tag
    if (index === 63) {
      tag = buffer.toString('latin1', at, at + 4)
      at += 4
    } else {
      tag = KNOWN_TAGS[index]
    }

    let origLength
    ;[origLength, at] = readBase128(buffer, at)

    /*
     * A transformed table occupies transformLength bytes in the stream rather
     * than origLength. Only glyf and loca are ever transformed, and the null
     * transform is version 3 for those two and version 0 for everything else —
     * so the metric tables are always stored whole, but their OFFSET depends
     * on getting every preceding length right.
     */
    const transformVersion = (flags >> 6) & 0x03
    const transformed = (tag === 'glyf' || tag === 'loca')
      ? transformVersion !== 3
      : transformVersion !== 0

    let length = origLength
    if (transformed) {
      ;[length, at] = readBase128(buffer, at)
    }

    directory.push({ tag, length })
  }

  const decompressed = brotliDecompressSync(buffer.subarray(at))

  const out = {}
  let cursor = 0

  for (const { tag, length } of directory) {
    out[tag] = decompressed.subarray(cursor, cursor + length)
    cursor += length
  }

  return out
}

function metrics(file) {
  const t = tables(file)

  for (const required of ['head', 'hhea', 'OS/2']) {
    if (!t[required]) {
      throw new Error(`${file} has no ${required} table`)
    }
  }

  const unitsPerEm = t.head.readUInt16BE(18)

  const ascender = t.hhea.readInt16BE(4)
  const descender = t.hhea.readInt16BE(6)
  const lineGap = t.hhea.readInt16BE(8)

  const os2Version = t['OS/2'].readUInt16BE(0)
  // sxHeight only exists from OS/2 version 2 onward, at byte 86.
  const xHeight = os2Version >= 2 ? t['OS/2'].readInt16BE(86) : null

  return {
    unitsPerEm,
    ascender: ascender / unitsPerEm,
    descender: Math.abs(descender) / unitsPerEm,
    lineGap: lineGap / unitsPerEm,
    xHeight: xHeight === null ? null : xHeight / unitsPerEm,
  }
}

const result = {}

for (const file of process.argv.slice(2)) {
  result[file] = metrics(file)
}

process.stdout.write(JSON.stringify(result))
