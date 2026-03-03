import { useState, useEffect } from '@wordpress/element';
import { useApi } from '../hooks/useApi';
import { PriceChart } from './PriceChart';

export function ProductDetail( { productId, onBack } ) {
	const { fetchApi } = useApi();
	const [ product, setProduct ] = useState( null );
	const [ history, setHistory ] = useState( [] );
	const [ stats, setStats ] = useState( null );
	const [ loading, setLoading ] = useState( true );

	useEffect( () => {
		const load = async () => {
			try {
				const [ productsRes, historyRes ] = await Promise.all( [
					fetchApi( `/products?search=` ),
					fetchApi( `/products/${ productId }/price-history` ),
				] );

				const found = productsRes.data.find(
					( p ) => String( p.id ) === String( productId )
				);
				setProduct( found || null );
				setHistory( historyRes.data.history || [] );
				setStats( historyRes.data.stats || null );
			} catch ( err ) {
				// Silent fail.
			}
			setLoading( false );
		};
		load();
	}, [ productId ] );

	if ( loading ) {
		return <div className="grt-loading">Loading product...</div>;
	}

	return (
		<div className="grt-product-detail">
			<button className="grt-btn grt-btn-secondary" onClick={ onBack }>
				&larr; Back
			</button>

			<h2>{ product?.canonical_name || 'Product' }</h2>

			{ product?.brand && (
				<p className="grt-product-brand">Brand: { product.brand }</p>
			) }
			{ product?.category && (
				<p className="grt-product-category">
					Category: { product.category }
				</p>
			) }

			{ stats && (
				<div className="grt-price-stats">
					<div className="grt-stat-card">
						<span className="grt-stat-value">
							&euro;{ stats.current?.toFixed( 2 ) }
						</span>
						<span className="grt-stat-label">Current</span>
					</div>
					<div className="grt-stat-card">
						<span className="grt-stat-value">
							&euro;{ stats.min?.toFixed( 2 ) }
						</span>
						<span className="grt-stat-label">Lowest</span>
					</div>
					<div className="grt-stat-card">
						<span className="grt-stat-value">
							&euro;{ stats.max?.toFixed( 2 ) }
						</span>
						<span className="grt-stat-label">Highest</span>
					</div>
					<div className="grt-stat-card">
						<span className="grt-stat-value">
							&euro;{ stats.avg?.toFixed( 2 ) }
						</span>
						<span className="grt-stat-label">Average</span>
					</div>
				</div>
			) }

			<h3>Price History</h3>
			<PriceChart data={ history } />

			<style>{ `
				.grt-product-detail h2 {
					margin-top: 16px;
				}
				.grt-product-brand, .grt-product-category {
					color: #666;
					font-size: 13px;
					margin: 2px 0;
				}
				.grt-price-stats {
					display: grid;
					grid-template-columns: repeat(4, 1fr);
					gap: 8px;
					margin: 16px 0;
				}
			` }</style>
		</div>
	);
}
