import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { gzipSync } from 'node:zlib'

const dist = new URL('../dist/', import.meta.url).pathname
const html = readFileSync(join(dist, 'index.html'), 'utf8')
const initial = [...html.matchAll(/assets\/[^"]+\.js/g)].map((m) => m[0])
let gz = 0
const offenders = []
for (const f of initial) {
  const src = readFileSync(join(dist, f))
  gz += gzipSync(src).length
  if (f.includes('vendor-charts') || /from"\.\/vendor-charts-/.test(src.toString())) offenders.push(f)
}
console.log(`initial JS files: ${initial.length}, gzip: ${(gz / 1024).toFixed(1)} kB`)
if (offenders.length) {
  console.error(`vendor-charts reachable from entry via: ${offenders.join(', ')}`)
  process.exit(1)
}
console.log('OK: vendor-charts not in initial load')
