import { useState, useEffect } from '@wordpress/element';
import { useApi } from '../hooks/useApi';

export function ReceiptReview( { scanResult, onSaved, onCancel } ) {
    const { fetchApi } = useApi();
    const [ store, setStore ] = useState( scanResult.store || '' );
    const [ date, setDate ] = useState( scanResult.date || '' );
    const [ items, setItems ] = useState( scanResult.items || [] );
    const [ products, setProducts ] = useState( [] );
    const [ saving, setSaving ] = useState( false );
    const [ error, setError ] = useState( null );

    useEffect( () => {
        fetchApi( '/products' )
            .then( ( { data } ) => setProducts( data ) )
            .catch( () => {} );
    }, [] );

    const updateItem = ( index, field, value ) => {
        setItems( ( prev ) =>
            prev.map( ( item, i ) => {
                if ( i !== index ) return item;
                const updated = { ...item, [ field ]: value };
                // Recalculate final price if original_price or discount changed.
                if ( field === 'original_price' || field === 'discount' ) {
                    updated.final_price =
                        parseFloat( updated.original_price || 0 ) -
                        parseFloat( updated.discount || 0 );
                }
                return updated;
            } )
        );
    };

    const removeItem = ( index ) => {
        setItems( ( prev ) => prev.filter( ( _, i ) => i !== index ) );
    };

    const addItem = () => {
        setItems( ( prev ) => [
            ...prev,
            {
                name: '',
                quantity: 1,
                original_price: 0,
                discount: 0,
                final_price: 0,
            },
        ] );
    };

    const handleSave = async () => {
        setSaving( true );
        setError( null );

        try {
            await fetchApi( '/receipts', {
                method: 'POST',
                body: {
                    store,
                    date,
                    items,
                    raw_text: scanResult.raw_text || '',
                    attachment_id: scanResult.attachment_id || 0,
                },
            } );
            onSaved();
        } catch ( err ) {
            setError( err.message );
            setSaving( false );
        }
    };

    const total = items.reduce(
        ( sum, item ) => sum + parseFloat( item.final_price || 0 ),
        0
    );

    return (
        <div className="grt-review">
            <h2>Review Receipt</h2>

            { error && <div className="grt-error">{ error }</div> }

            <div className="grt-review-header">
                <div className="grt-field">
                    <label>Store</label>
                    <input
                        className="grt-input"
                        value={ store }
                        onChange={ ( e ) => setStore( e.target.value ) }
                    />
                </div>
                <div className="grt-field">
                    <label>Date</label>
                    <input
                        className="grt-input"
                        type="date"
                        value={ date }
                        onChange={ ( e ) => setDate( e.target.value ) }
                    />
                </div>
            </div>

            <div className="grt-items-table">
                <div className="grt-items-header">
                    <span>Item</span>
                    <span>Qty</span>
                    <span>Price</span>
                    <span>Disc.</span>
                    <span>Final</span>
                    <span></span>
                </div>

                { items.map( ( item, i ) => (
                    <div key={ i } className="grt-item-row">
                        <input
                            className="grt-input"
                            value={ item.name }
                            onChange={ ( e ) =>
                                updateItem( i, 'name', e.target.value )
                            }
                            placeholder="Item name"
                            list="grt-products-list"
                        />
                        <input
                            className="grt-input grt-input-sm"
                            type="number"
                            step="0.001"
                            value={ item.quantity }
                            onChange={ ( e ) =>
                                updateItem( i, 'quantity', e.target.value )
                            }
                        />
                        <input
                            className="grt-input grt-input-sm"
                            type="number"
                            step="0.01"
                            value={ item.original_price }
                            onChange={ ( e ) =>
                                updateItem(
                                    i,
                                    'original_price',
                                    e.target.value
                                )
                            }
                        />
                        <input
                            className="grt-input grt-input-sm"
                            type="number"
                            step="0.01"
                            value={ item.discount }
                            onChange={ ( e ) =>
                                updateItem( i, 'discount', e.target.value )
                            }
                        />
                        <span className="grt-item-final">
                            { parseFloat( item.final_price || 0 ).toFixed( 2 ) }
                        </span>
                        <button
                            className="grt-btn-icon"
                            onClick={ () => removeItem( i ) }
                            title="Remove item"
                        >
                            &times;
                        </button>
                    </div>
                ) ) }
            </div>

            <datalist id="grt-products-list">
                { products.map( ( p ) => (
                    <option key={ p.id } value={ p.canonical_name } />
                ) ) }
            </datalist>

            <div className="grt-review-footer">
                <button
                    className="grt-btn grt-btn-secondary"
                    onClick={ addItem }
                >
                    + Add Item
                </button>

                <div className="grt-total">
                    Total: &euro;{ total.toFixed( 2 ) }
                </div>

                <div className="grt-review-actions">
                    <button
                        className="grt-btn grt-btn-secondary"
                        onClick={ onCancel }
                    >
                        Cancel
                    </button>
                    <button
                        className="grt-btn grt-btn-primary"
                        onClick={ handleSave }
                        disabled={ saving || items.length === 0 }
                    >
                        { saving ? 'Saving...' : 'Save Receipt' }
                    </button>
                </div>
            </div>

            <style>{ `
                .grt-review-header {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 12px;
                    margin-bottom: 16px;
                }
                .grt-field label {
                    display: block;
                    font-size: 12px;
                    font-weight: 600;
                    margin-bottom: 4px;
                    color: #555;
                }
                .grt-items-header {
                    display: grid;
                    grid-template-columns: 2fr 0.5fr 0.7fr 0.7fr 0.7fr 30px;
                    gap: 6px;
                    font-size: 11px;
                    font-weight: 600;
                    color: #888;
                    padding: 8px 0;
                    border-bottom: 1px solid #e0e0e0;
                }
                .grt-item-row {
                    display: grid;
                    grid-template-columns: 2fr 0.5fr 0.7fr 0.7fr 0.7fr 30px;
                    gap: 6px;
                    padding: 6px 0;
                    align-items: center;
                    border-bottom: 1px solid #f0f0f0;
                }
                .grt-input-sm {
                    padding: 6px;
                    font-size: 13px;
                }
                .grt-item-final {
                    font-weight: 600;
                    font-size: 13px;
                    text-align: right;
                }
                .grt-btn-icon {
                    border: none;
                    background: none;
                    font-size: 18px;
                    color: #999;
                    cursor: pointer;
                    padding: 0;
                }
                .grt-btn-icon:hover { color: #d32f2f; }
                .grt-review-footer {
                    margin-top: 16px;
                }
                .grt-total {
                    font-size: 18px;
                    font-weight: 700;
                    text-align: right;
                    padding: 12px 0;
                }
                .grt-review-actions {
                    display: flex;
                    gap: 12px;
                    justify-content: flex-end;
                }
            ` }</style>
        </div>
    );
}
