const CACHE_NAME = 'grt-cache-v1';
const PRECACHE_URLS = [];

self.addEventListener( 'install', ( event ) => {
    event.waitUntil(
        caches
            .open( CACHE_NAME )
            .then( ( cache ) => cache.addAll( PRECACHE_URLS ) )
            .then( () => self.skipWaiting() )
    );
} );

self.addEventListener( 'activate', ( event ) => {
    event.waitUntil(
        caches.keys().then( ( names ) =>
            Promise.all(
                names
                    .filter( ( name ) => name !== CACHE_NAME )
                    .map( ( name ) => caches.delete( name ) )
            )
        ).then( () => self.clients.claim() )
    );
} );

self.addEventListener( 'fetch', ( event ) => {
    if ( event.request.method !== 'GET' ) return;
    if ( event.request.url.includes( '/wp-json/' ) ) return;

    event.respondWith(
        caches.match( event.request ).then( ( cached ) => {
            const fetchPromise = fetch( event.request )
                .then( ( response ) => {
                    if ( response.ok ) {
                        const clone = response.clone();
                        caches
                            .open( CACHE_NAME )
                            .then( ( cache ) =>
                                cache.put( event.request, clone )
                            );
                    }
                    return response;
                } )
                .catch( () => cached );

            return cached || fetchPromise;
        } )
    );
} );
