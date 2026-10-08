// Serves Mobile/build/web on localhost:5000 (the API's CORS allows that origin
// in local env). Build first:
//   flutter build web --release --dart-define=API_BASE_URL=http://localhost:8000/api
// Missing files get a real 404, so a bad path names itself instead of handing
// index.html to the JS parser.
const http = require('http')
const fs = require('fs')
const path = require('path')
const { REPO } = require('./common')

const ROOT = path.resolve(REPO, 'Mobile/build/web')
const TYPES = { '.html': 'text/html', '.js': 'text/javascript', '.mjs': 'text/javascript', '.json': 'application/json', '.wasm': 'application/wasm', '.png': 'image/png', '.otf': 'font/otf', '.ttf': 'font/ttf', '.css': 'text/css', '.ico': 'image/x-icon', '.bin': 'application/octet-stream', '.frag': 'application/octet-stream' }

http.createServer((req, res) => {
  const rel = decodeURIComponent(new URL(req.url, 'http://localhost').pathname)
  const file = path.resolve(ROOT, '.' + (rel === '/' ? '/index.html' : rel))
  if (!file.startsWith(ROOT) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) {
    res.writeHead(404); res.end('not found: ' + rel); return
  }
  res.writeHead(200, { 'Content-Type': TYPES[path.extname(file)] || 'application/octet-stream' })
  fs.createReadStream(file).pipe(res)
}).listen(5000, 'localhost', () => console.log('Serving', ROOT, 'on http://localhost:5000'))
