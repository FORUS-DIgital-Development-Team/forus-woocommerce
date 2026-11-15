import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';
const root = fileURLToPath(new URL('../', import.meta.url));
const files = ['forus-woocommerce.php', ...fs.readdirSync(path.join(root, 'includes')).filter(x => x.endsWith('.php')).map(x => 'includes/' + x)];
for (const file of files) {
	const text = fs.readFileSync(path.join(root, file), 'utf8');
	assert.match(text, /^<\?php\r?\n/, file + ': opening tag/BOM');
	assert.equal(text.includes("defined( 'ABSPATH' ) || exit;"), true, file + ': direct access');
	assert.equal(/[ \t]+\r?$/m.test(text), false, file + ': trailing whitespace');
	assert.equal(/\b(?:eval|exec|shell_exec|system|passthru|unserialize|update_post_meta|get_post_meta|file_put_contents)\s*\(/i.test(text), false, file + ': unsafe API or non-HPOS access');
	assert.equal(/\b(?:error_log|var_dump|print_r)\s*\(/.test(text), false, file + ': unredacted debugging');
}
assert.match(fs.readFileSync(path.join(root, 'includes/class-forus-api.php'), 'utf8'), /public function ready\(\): bool\s*\{[\s\S]*?return false;/);
assert.equal(/contents:\s*write|action-gh-release|gh release create/.test(fs.readFileSync(path.join(root, '.github/workflows/release.yml'), 'utf8')), false, 'No automatic publication');
console.log('PASS source safeguards, formatting, HPOS access and release gate (' + files.length + ' PHP files).');
