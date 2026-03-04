import { useState, useRef } from '@wordpress/element';
import { useApi } from '../hooks/useApi';
import { compressImage } from '../utils/imageCompressor';

export function CameraCapture( { onScanComplete } ) {
	const [ status, setStatus ] = useState( 'idle' );
	const [ error, setError ] = useState( null );
	const fileInputRef = useRef( null );
	const { fetchApi } = useApi();

	const handleFile = async ( file ) => {
		if ( ! file ) return;

		setStatus( 'uploading' );
		setError( null );

		try {
			const compressed = await compressImage( file );
			const formData = new FormData();
			formData.append( 'receipt', compressed, 'receipt.jpg' );

			const { data } = await fetchApi( '/receipts/scan', {
				method: 'POST',
				body: formData,
				isFormData: true,
			} );

			onScanComplete( data );
		} catch ( err ) {
			setError( err.message );
			setStatus( 'error' );
		}
	};

	const handleFileInput = ( e ) => {
		const file = e.target.files?.[ 0 ];
		if ( file ) {
			handleFile( file );
		}
	};

	const handleCapture = () => {
		fileInputRef.current?.click();
	};

	return (
		<div className="grt-camera">
			<h2>Scan Receipt</h2>

			{ error && <div className="grt-error">{ error }</div> }

			{ status === 'uploading' ? (
				<div className="grt-loading">
					<p>Processing receipt...</p>
					<p style={ { fontSize: '12px', color: '#999' } }>
						Uploading and running OCR
					</p>
				</div>
			) : (
				<div className="grt-camera-actions">
					<input
						ref={ fileInputRef }
						type="file"
						accept="image/*"
						capture="environment"
						onChange={ handleFileInput }
						style={ { display: 'none' } }
					/>

					<button
						className="grt-btn grt-btn-primary grt-camera-btn"
						onClick={ handleCapture }
					>
						Take Photo
					</button>

					<label className="grt-btn grt-btn-secondary grt-camera-btn">
						Choose from Gallery
						<input
							type="file"
							accept="image/*"
							onChange={ handleFileInput }
							style={ { display: 'none' } }
						/>
					</label>
				</div>
			) }

			<style>{ `
				.grt-camera {
					text-align: center;
					padding: 24px 0;
				}
				.grt-camera-actions {
					display: flex;
					flex-direction: column;
					gap: 12px;
					max-width: 300px;
					margin: 24px auto;
				}
				.grt-camera-btn {
					padding: 16px 24px;
					font-size: 16px;
				}
			` }</style>
		</div>
	);
}
