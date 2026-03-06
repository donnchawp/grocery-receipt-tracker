import { useState } from '@wordpress/element';
import { CameraCapture } from './components/CameraCapture';
import { CsvImport } from './components/CsvImport';
import { ReceiptReview } from './components/ReceiptReview';
import { ReceiptList } from './components/ReceiptList';
import { Dashboard } from './components/Dashboard';
import { ProductDetail } from './components/ProductDetail';

const SCREENS = {
	DASHBOARD: 'DASHBOARD',
	CAMERA: 'CAMERA',
	CSV_IMPORT: 'CSV_IMPORT',
	REVIEW: 'REVIEW',
	RECEIPTS: 'RECEIPTS',
	PRODUCT: 'PRODUCT',
};

export function App() {
	const [ screen, setScreen ] = useState( SCREENS.DASHBOARD );
	const [ scanResult, setScanResult ] = useState( null );
	const [ selectedProductId, setSelectedProductId ] = useState( null );

	const navigate = ( target, data = {} ) => {
		if ( data.scanResult !== undefined ) {
			setScanResult( data.scanResult );
		}
		if ( data.productId !== undefined ) {
			setSelectedProductId( data.productId );
		}
		setScreen( target );
	};

	const renderScreen = () => {
		switch ( screen ) {
			case SCREENS.CAMERA:
				return (
					<CameraCapture
						onScanComplete={ ( result ) =>
							navigate( SCREENS.REVIEW, {
								scanResult: result,
							} )
						}
					/>
				);
			case SCREENS.CSV_IMPORT:
				return (
					<CsvImport
						onResult={ ( result ) =>
							navigate( SCREENS.REVIEW, {
								scanResult: result,
							} )
						}
					/>
				);
			case SCREENS.REVIEW:
				if ( ! scanResult ) {
					return <Dashboard onNavigate={ navigate } />;
				}
				return (
					<ReceiptReview
						scanResult={ scanResult }
						onSaved={ () => navigate( SCREENS.RECEIPTS ) }
						onCancel={ () => navigate( SCREENS.DASHBOARD ) }
					/>
				);
			case SCREENS.RECEIPTS:
				return (
					<ReceiptList
						onSelectProduct={ ( productId ) =>
							navigate( SCREENS.PRODUCT, { productId } )
						}
					/>
				);
			case SCREENS.PRODUCT:
				return (
					<ProductDetail
						productId={ selectedProductId }
						onBack={ () => navigate( SCREENS.RECEIPTS ) }
					/>
				);
			case SCREENS.DASHBOARD:
			default:
				return <Dashboard onNavigate={ navigate } />;
		}
	};

	return (
		<div className="grt-app">
			<nav className="grt-nav">
				<button
					className={
						screen === SCREENS.DASHBOARD ? 'active' : ''
					}
					onClick={ () => navigate( SCREENS.DASHBOARD ) }
				>
					Dashboard
				</button>
				<button
					className={
						screen === SCREENS.CAMERA ? 'active' : ''
					}
					onClick={ () => navigate( SCREENS.CAMERA ) }
				>
					Scan
				</button>
				<button
					className={
						screen === SCREENS.RECEIPTS ? 'active' : ''
					}
					onClick={ () => navigate( SCREENS.RECEIPTS ) }
				>
					Receipts
				</button>
			</nav>
			<main className="grt-main">{ renderScreen() }</main>
		</div>
	);
}
