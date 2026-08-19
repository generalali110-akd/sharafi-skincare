import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import process from 'node:process';

const root = path.resolve(process.cwd(), 'frontend');
const port = Number(process.argv[2] || process.env.FRONTEND_PORT || 4173);
const host = process.env.FRONTEND_HOST || '127.0.0.1';

const types = new Map([
  ['.css', 'text/css; charset=utf-8'],
  ['.gif', 'image/gif'],
  ['.html', 'text/html; charset=utf-8'],
  ['.jpeg', 'image/jpeg'],
  ['.jpg', 'image/jpeg'],
  ['.js', 'text/javascript; charset=utf-8'],
  ['.json', 'application/json; charset=utf-8'],
  ['.png', 'image/png'],
  ['.svg', 'image/svg+xml'],
  ['.ttf', 'font/ttf'],
  ['.webp', 'image/webp'],
]);

function resolveFile(url) {
  const requestUrl = new URL(url, `http://${host}:${port}`);
  const pathname = decodeURIComponent(requestUrl.pathname === '/' ? '/index.html' : requestUrl.pathname);
  const file = path.normalize(path.join(root, pathname));

  if (!file.startsWith(root + path.sep) && file !== root) return null;

  return file;
}

const server = http.createServer((request, response) => {
  const file = resolveFile(request.url || '/');
  if (!file) {
    response.writeHead(403);
    response.end('Forbidden');
    return;
  }

  fs.stat(file, (error, stat) => {
    if (error || !stat.isFile()) {
      response.writeHead(404);
      response.end('Not found');
      return;
    }

    response.writeHead(200, {
      'Content-Type': types.get(path.extname(file).toLowerCase()) || 'application/octet-stream',
    });
    fs.createReadStream(file).pipe(response);
  });
});

server.listen(port, host, () => {
  console.log(`Frontend static server listening on http://${host}:${port}`);
});
