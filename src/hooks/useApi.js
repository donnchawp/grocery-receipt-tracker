const API_URL = window.grtSettings?.apiUrl || '/wp-json/grt/v1';
const NONCE = window.grtSettings?.nonce || '';

export function useApi() {
	const fetchApi = async ( endpoint, options = {} ) => {
		const { method = 'GET', body, isFormData = false } = options;

		const headers = {
			'X-WP-Nonce': NONCE,
		};

		if ( ! isFormData ) {
			headers[ 'Content-Type' ] = 'application/json';
		}

		const config = {
			method,
			headers,
			credentials: 'same-origin',
		};

		if ( body ) {
			config.body = isFormData ? body : JSON.stringify( body );
		}

		// When API_URL already contains query params (e.g. ?rest_route=...),
		// any ? in the endpoint must become & to avoid a malformed URL.
		const separator = API_URL.includes( '?' ) ? '&' : '?';
		const url = `${ API_URL }${ endpoint.replace( '?', separator ) }`;
		const response = await fetch( url, config );

		if ( ! response.ok ) {
			const error = await response.json().catch( () => ( {} ) );
			throw new Error(
				error.message || `API error: ${ response.status }`
			);
		}

		return {
			data: await response.json(),
			headers: {
				total: parseInt(
					response.headers.get( 'X-WP-Total' ) || '0',
					10
				),
				totalPages: parseInt(
					response.headers.get( 'X-WP-TotalPages' ) || '0',
					10
				),
			},
		};
	};

	return { fetchApi };
}
