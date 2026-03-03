import { useState, useEffect } from '@wordpress/element';
import { useApi } from '../hooks/useApi';

export function Dashboard( { onNavigate } ) {
    const { fetchApi } = useApi();
    const [ receipts, setReceipts ] = useState( [] );
    const [ loading, setLoading ] = useState( true );

    useEffect( () => {
        fetchApi( '/receipts?per_page=5' )
            .then( ( { data } ) => setReceipts( data ) )
            .catch( () => {} )
            .finally( () => setLoading( false ) );
    }, [] );

    const totalSpend = receipts.reduce(
        ( sum, r ) => sum + parseFloat( r.total || 0 ),
        0
    );

    return (
        <div className="grt-dashboard">
            <h2>Grocery Tracker</h2>

            <div className="grt-stats">
                <div className="grt-stat-card">
                    <span className="grt-stat-value">{ receipts.length }</span>
                    <span className="grt-stat-label">Recent Receipts</span>
                </div>
                <div className="grt-stat-card">
                    <span className="grt-stat-value">
                        &euro;{ totalSpend.toFixed( 2 ) }
                    </span>
                    <span className="grt-stat-label">Recent Spend</span>
                </div>
            </div>

            <button
                className="grt-btn grt-btn-primary grt-scan-btn"
                onClick={ () => onNavigate( 'camera' ) }
            >
                Scan Receipt
            </button>

            { loading ? (
                <div className="grt-loading">Loading...</div>
            ) : (
                <div className="grt-recent">
                    <h3>Recent Receipts</h3>
                    { receipts.length === 0 ? (
                        <p style={ { color: '#999' } }>
                            No receipts yet. Scan your first receipt!
                        </p>
                    ) : (
                        receipts.map( ( r ) => (
                            <div key={ r.id } className="grt-receipt-card">
                                <div>
                                    <strong>{ r.store }</strong>
                                    <span className="grt-receipt-date">
                                        { r.receipt_date }
                                    </span>
                                </div>
                                <span className="grt-receipt-total">
                                    &euro;{ parseFloat( r.total ).toFixed( 2 ) }
                                </span>
                            </div>
                        ) )
                    ) }
                </div>
            ) }

            <style>{ `
                .grt-stats {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 12px;
                    margin-bottom: 16px;
                }
                .grt-stat-card {
                    background: #f5f5f5;
                    border-radius: 8px;
                    padding: 16px;
                    text-align: center;
                }
                .grt-stat-value {
                    display: block;
                    font-size: 24px;
                    font-weight: 700;
                }
                .grt-stat-label {
                    font-size: 12px;
                    color: #666;
                }
                .grt-scan-btn {
                    width: 100%;
                    padding: 16px;
                    font-size: 16px;
                    margin-bottom: 24px;
                }
                .grt-receipt-card {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    padding: 12px;
                    border: 1px solid #e0e0e0;
                    border-radius: 6px;
                    margin-bottom: 8px;
                }
                .grt-receipt-date {
                    display: block;
                    font-size: 12px;
                    color: #999;
                }
                .grt-receipt-total {
                    font-weight: 700;
                    font-size: 16px;
                }
            ` }</style>
        </div>
    );
}
