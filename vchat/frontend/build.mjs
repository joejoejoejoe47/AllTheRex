// Bundles src/ into ../assets/app.js + app.css (React included). Run on any computer with Node:
//   cd vchat/frontend && npm install && npm run build
// and commit the files in ../assets. The server itself never needs Node.
import { build, context } from 'esbuild';

const options = {
  entryPoints: { app: 'src/main.jsx' },
  outdir: '../assets',
  bundle: true, minify: true, sourcemap: false, target: ['es2020'],
  jsx: 'automatic', loader: { '.js': 'jsx' },
  define: { 'process.env.NODE_ENV': '"production"' },
  legalComments: 'none', logLevel: 'info',
};
if (process.argv.includes('--watch')) { await (await context(options)).watch(); } else { await build(options); }
