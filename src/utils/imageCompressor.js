export function compressImage( file, maxWidth = 1500, quality = 0.8 ) {
	return new Promise( ( resolve, reject ) => {
		const reader = new FileReader();
		reader.onload = ( e ) => {
			const img = new Image();
			img.onload = () => {
				const canvas = document.createElement( 'canvas' );
				let { width, height } = img;

				if ( width > maxWidth ) {
					height = Math.round( ( height * maxWidth ) / width );
					width = maxWidth;
				}

				canvas.width = width;
				canvas.height = height;

				const ctx = canvas.getContext( '2d' );
				ctx.drawImage( img, 0, 0, width, height );

				canvas.toBlob(
					( blob ) => {
						if ( blob ) {
							resolve( blob );
						} else {
							reject(
								new Error( 'Image compression failed.' )
							);
						}
					},
					'image/jpeg',
					quality
				);
			};
			img.onerror = () =>
				reject( new Error( 'Failed to load image.' ) );
			img.src = e.target.result;
		};
		reader.onerror = () => reject( new Error( 'Failed to read file.' ) );
		reader.readAsDataURL( file );
	} );
}
