import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const source = fs.readFileSync(new URL('../assets/blocks.js', import.meta.url), 'utf8');
function run(settings) {
	let registered;
	const window = {
		wc: { wcSettings: { getSetting: () => settings }, wcBlocksRegistry: { registerPaymentMethod: x => { registered = x; } } },
		wp: { element: { createElement: (type, props, text) => ({ type, props, text }) }, htmlEntities: { decodeEntities: x => x } }
	};
	vm.runInNewContext(source, { window });
	return registered;
}
test('Blocks uses the same gateway ID and server availability gate', () => {
	assert.equal(run({}).name, 'forus');
	assert.equal(run({}).canMakePayment(), false);
	assert.equal(run({ available: 'yes' }).canMakePayment(), false);
	assert.equal(run({ available: true }).canMakePayment(), true);
});
test('Labels and descriptions are text nodes, never HTML', () => {
	const result = run({ title: '<img src=x onerror=alert(1)>', description: '<script>bad</script>', api_key: 'DO-NOT-EXPOSE', webhook_secret: 'DO-NOT-EXPOSE' });
	assert.equal(result.label.text, '<img src=x onerror=alert(1)>');
	assert.equal(result.content.text, '<script>bad</script>');
	assert.equal(JSON.stringify(result).includes('DO-NOT-EXPOSE'), false);
	assert.equal('dangerouslySetInnerHTML' in result.content, false);
});
