(function () {
	'use strict';
	const settings = window.wc.wcSettings.getSetting('forus_data', {});
	const el = window.wp.element.createElement;
	const decode = window.wp.htmlEntities.decodeEntities;
	const title = decode(settings.title || 'FORUS');
	const content = el('span', null, decode(settings.description || 'Continue to FORUS secure checkout.'));
	window.wc.wcBlocksRegistry.registerPaymentMethod({
		name: 'forus',
		label: el('span', null, title),
		ariaLabel: title,
		content: content,
		edit: content,
		canMakePayment: function () { return settings.available === true; },
		supports: { features: settings.supports || ['products'] }
	});
}());
