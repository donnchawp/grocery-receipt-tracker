import { createRoot } from '@wordpress/element';
import { App } from './App';
import './index.css';

const container = document.getElementById( 'grt-app' );
if ( container ) {
	createRoot( container ).render( <App /> );
}
