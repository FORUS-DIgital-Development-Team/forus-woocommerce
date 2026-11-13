/* global wc, wp */
( function () {
    'use strict';
    const settings = wc.wcSettings.getSetting( 'forus_data', {} );
    const title = wp.htmlEntities.decodeEntities( settings.title || 'FORUS' );
    const content = wp.element.createElement( 'span', null,
        wp.htmlEntities.decodeEntities( settings.description || 'Pay using FORUS.' ) );
    wc.wcBlocksRegistry.registerPaymentMethod( {
        name: 'forus',
        paymentMethodId: 'forus',
        label: wp.element.createElement( 'span', null, title ),
        ariaLabel: title,
        content: content,
        edit: content,
        canMakePayment: function () { return settings.enabled === true; },
        supports: { features: settings.supports || [ 'products' ] }
    } );
}() );
