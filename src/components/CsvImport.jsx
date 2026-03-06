import { useState } from '@wordpress/element';

function parseCsv( text ) {
	const lines = text.trim().split( '\n' ).map( ( l ) => l.trim() ).filter( Boolean );

	if ( lines.length < 4 ) {
		throw new Error( 'CSV must have at least 4 rows (metadata header, metadata, items header, and at least one item).' );
	}

	// Row 0: metadata headers — validate
	const metaHeaders = lines[ 0 ].split( ',' ).map( ( h ) => h.trim().toLowerCase() );
	if (
		metaHeaders[ 0 ] !== 'store' ||
		metaHeaders[ 1 ] !== 'date' ||
		metaHeaders[ 2 ] !== 'voucher_discount'
	) {
		throw new Error( 'First row must be: store,date,voucher_discount' );
	}

	// Row 1: metadata values
	const metaValues = lines[ 1 ].split( ',' ).map( ( v ) => v.trim() );
	const store = metaValues[ 0 ];
	const date = metaValues[ 1 ];
	const voucherDiscount = parseFloat( metaValues[ 2 ] ) || 0;

	if ( ! store ) {
		throw new Error( 'Store name is empty.' );
	}
	if ( ! /^\d{4}-\d{2}-\d{2}$/.test( date ) ) {
		throw new Error( 'Date must be YYYY-MM-DD format.' );
	}

	// Row 2: item headers — validate
	const itemHeaders = lines[ 2 ].split( ',' ).map( ( h ) => h.trim().toLowerCase() );
	if (
		itemHeaders[ 0 ] !== 'name' ||
		itemHeaders[ 1 ] !== 'quantity' ||
		itemHeaders[ 2 ] !== 'price' ||
		itemHeaders[ 3 ] !== 'discount'
	) {
		throw new Error( 'Third row must be: name,quantity,price,discount' );
	}

	// Rows 3+: items
	const items = [];
	for ( let i = 3; i < lines.length; i++ ) {
		const parts = lines[ i ].split( ',' ).map( ( v ) => v.trim() );
		if ( parts.length < 4 ) {
			throw new Error( `Row ${ i + 1 }: expected at least 4 columns.` );
		}

		const name = parts[ 0 ];
		const quantity = parseFloat( parts[ 1 ] );
		const originalPrice = parseFloat( parts[ 2 ] );
		const discount = parseFloat( parts[ 3 ] );

		if ( ! name ) {
			throw new Error( `Row ${ i + 1 }: item name is empty.` );
		}
		if ( isNaN( quantity ) || isNaN( originalPrice ) || isNaN( discount ) ) {
			throw new Error( `Row ${ i + 1 }: quantity, price, and discount must be numbers.` );
		}

		items.push( {
			name,
			quantity,
			original_price: originalPrice,
			discount,
			final_price: originalPrice - discount,
		} );
	}

	return {
		store,
		date,
		voucher_discount: voucherDiscount,
		items,
		raw_text: text,
		attachment_id: 0,
	};
}

export function CsvImport( { onResult } ) {
	const [ csv, setCsv ] = useState( '' );
	const [ error, setError ] = useState( null );

	const handleParse = () => {
		setError( null );
		try {
			const result = parseCsv( csv );
			onResult( result );
		} catch ( err ) {
			setError( err.message );
		}
	};

	return (
		<div className="grt-csv-import">
			<h2>Paste CSV</h2>
			<p style={ { color: '#666', fontSize: '13px', margin: '0 0 12px' } }>
				Paste receipt data from ChatGPT/Claude in CSV format.
			</p>

			<textarea
				className="grt-input"
				rows="12"
				value={ csv }
				onChange={ ( e ) => setCsv( e.target.value ) }
				placeholder={
					'store,date,voucher_discount\nSuperValu,2026-02-04,5.00\nname,quantity,price,discount\nCOLGATE PLAX,1,3.00,0\nSV BLACKBERRY,1,2.79,0'
				}
				style={ { width: '100%', fontFamily: 'monospace', fontSize: '13px' } }
			/>

			{ error && (
				<div className="grt-error" style={ { marginTop: '8px' } }>
					{ error }
				</div>
			) }

			<button
				className="grt-btn grt-btn-primary"
				onClick={ handleParse }
				disabled={ ! csv.trim() }
				style={ { width: '100%', padding: '12px', marginTop: '12px' } }
			>
				Parse & Review
			</button>
		</div>
	);
}
