import { useState, useEffect } from '@wordpress/element';
import { useApi } from '../hooks/useApi';

export function ProductSearch( { onSelect } ) {
	const { fetchApi } = useApi();
	const [ query, setQuery ] = useState( '' );
	const [ results, setResults ] = useState( [] );

	useEffect( () => {
		if ( query.length < 2 ) {
			setResults( [] );
			return;
		}

		const timer = setTimeout( async () => {
			try {
				const { data } = await fetchApi(
					`/products?search=${ encodeURIComponent( query ) }`
				);
				setResults( data );
			} catch ( err ) {
				// Silent fail.
			}
		}, 300 );

		return () => clearTimeout( timer );
	}, [ query ] );

	return (
		<div className="grt-product-search">
			<input
				className="grt-input"
				placeholder="Search products..."
				value={ query }
				onChange={ ( e ) => setQuery( e.target.value ) }
			/>
			{ results.length > 0 && (
				<div className="grt-search-results">
					{ results.map( ( p ) => (
						<div
							key={ p.id }
							className="grt-search-result"
							onClick={ () => onSelect( p ) }
						>
							<strong>{ p.canonical_name }</strong>
							{ p.brand && (
								<span className="grt-search-brand">
									{ p.brand }
								</span>
							) }
						</div>
					) ) }
				</div>
			) }

			<style>{ `
				.grt-product-search { position: relative; }
				.grt-search-results {
					position: absolute;
					top: 100%;
					left: 0;
					right: 0;
					background: #fff;
					border: 1px solid #ccc;
					border-radius: 0 0 4px 4px;
					max-height: 200px;
					overflow-y: auto;
					z-index: 20;
				}
				.grt-search-result {
					padding: 8px 12px;
					cursor: pointer;
					font-size: 13px;
				}
				.grt-search-result:hover { background: #f0f0f0; }
				.grt-search-brand {
					display: block;
					font-size: 11px;
					color: #999;
				}
			` }</style>
		</div>
	);
}
