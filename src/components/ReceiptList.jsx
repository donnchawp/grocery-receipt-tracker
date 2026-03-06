import { useState, useEffect } from '@wordpress/element';
import { useApi } from '../hooks/useApi';

export function ReceiptList( { onSelectProduct } ) {
    const { fetchApi } = useApi();
    const [ receipts, setReceipts ] = useState( [] );
    const [ expanded, setExpanded ] = useState( null );
    const [ expandedItems, setExpandedItems ] = useState( [] );
    const [ page, setPage ] = useState( 1 );
    const [ totalPages, setTotalPages ] = useState( 1 );
    const [ loading, setLoading ] = useState( true );

    const loadReceipts = async ( p ) => {
        setLoading( true );
        try {
            const { data, headers } = await fetchApi(
                `/receipts?page=${ p }&per_page=20`
            );
            setReceipts( data );
            setTotalPages( headers.totalPages );
        } catch ( err ) {
            // Silent fail — show empty state.
        }
        setLoading( false );
    };

    useEffect( () => {
        loadReceipts( page );
    }, [ page ] );

    const toggleExpand = async ( id ) => {
        if ( expanded === id ) {
            setExpanded( null );
            return;
        }

        try {
            const { data } = await fetchApi( `/receipts/${ id }` );
            setExpandedItems( data.items || [] );
            setExpanded( id );
        } catch ( err ) {
            // Silent fail.
        }
    };

    const handleDelete = async ( id ) => {
        if ( ! window.confirm( 'Delete this receipt?' ) ) return;

        try {
            await fetchApi( `/receipts/${ id }`, { method: 'DELETE' } );
            setReceipts( ( prev ) => prev.filter( ( r ) => r.id !== id ) );
            if ( expanded === id ) setExpanded( null );
        } catch ( err ) {
            alert( 'Failed to delete receipt.' );
        }
    };

    if ( loading ) {
        return <div className="grt-loading">Loading receipts...</div>;
    }

    return (
        <div className="grt-receipts">
            <h2>Receipts</h2>

            { receipts.length === 0 ? (
                <p style={ { color: '#999' } }>No receipts found.</p>
            ) : (
                receipts.map( ( r ) => (
                    <div key={ r.id } className="grt-receipt-item">
                        <div
                            className="grt-receipt-summary"
                            onClick={ () => toggleExpand( r.id ) }
                        >
                            <div>
                                <strong>{ r.store }</strong>
                                <span className="grt-receipt-date">
                                    { r.receipt_date }
                                </span>
                            </div>
                            <div className="grt-receipt-right">
                                <span className="grt-receipt-total">
                                    &euro;
                                    { parseFloat( r.total ).toFixed( 2 ) }
                                </span>
                                <button
                                    className="grt-btn-icon"
                                    onClick={ ( e ) => {
                                        e.stopPropagation();
                                        handleDelete( r.id );
                                    } }
                                    title="Delete"
                                >
                                    &times;
                                </button>
                            </div>
                        </div>

                        { expanded === r.id && (
                            <div className="grt-receipt-detail">
                                { expandedItems.map( ( item, i ) => (
                                    <div key={ i } className="grt-detail-row">
                                        <span
                                            className="grt-detail-name"
                                            onClick={ () => {
                                                if ( item.product_id ) {
                                                    onSelectProduct(
                                                        item.product_id
                                                    );
                                                }
                                            } }
                                            style={
                                                item.product_id
                                                    ? { cursor: 'pointer', color: '#0073aa' }
                                                    : {}
                                            }
                                        >
                                            { item.canonical_name ||
                                                item.raw_item_text }
                                        </span>
                                        <span>
                                            { item.quantity > 1
                                                ? `${ item.quantity }x `
                                                : '' }
                                            &euro;
                                            { parseFloat(
                                                item.final_price
                                            ).toFixed( 2 ) }
                                        </span>
                                    </div>
                                ) ) }
                            </div>
                        ) }
                    </div>
                ) )
            ) }

            { totalPages > 1 && (
                <div className="grt-pagination">
                    <button
                        className="grt-btn grt-btn-secondary"
                        disabled={ page <= 1 }
                        onClick={ () => setPage( page - 1 ) }
                    >
                        Previous
                    </button>
                    <span>
                        Page { page } of { totalPages }
                    </span>
                    <button
                        className="grt-btn grt-btn-secondary"
                        disabled={ page >= totalPages }
                        onClick={ () => setPage( page + 1 ) }
                    >
                        Next
                    </button>
                </div>
            ) }

            <style>{ `
                .grt-receipt-item {
                    border: 1px solid #e0e0e0;
                    border-radius: 6px;
                    margin-bottom: 8px;
                    overflow: hidden;
                }
                .grt-receipt-summary {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    padding: 12px;
                    cursor: pointer;
                }
                .grt-receipt-summary:hover {
                    background: #fafafa;
                }
                .grt-receipt-right {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                }
                .grt-receipt-detail {
                    border-top: 1px solid #e0e0e0;
                    padding: 8px 12px;
                    background: #fafafa;
                }
                .grt-detail-row {
                    display: flex;
                    justify-content: space-between;
                    padding: 4px 0;
                    font-size: 13px;
                }
                .grt-receipt-date {
                    margin-left: 8px;
                }
                .grt-pagination {
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    gap: 12px;
                    margin-top: 16px;
                }
            ` }</style>
        </div>
    );
}
