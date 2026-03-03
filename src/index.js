import { createRoot } from '@wordpress/element';

function App() {
    return <div>Grocery Receipt Tracker loading...</div>;
}

const container = document.getElementById( 'grt-app' );
if ( container ) {
    createRoot( container ).render( <App /> );
}
