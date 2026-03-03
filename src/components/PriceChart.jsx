import {
	LineChart,
	Line,
	XAxis,
	YAxis,
	CartesianGrid,
	Tooltip,
	ResponsiveContainer,
} from 'recharts';

export function PriceChart( { data } ) {
	if ( ! data || data.length === 0 ) {
		return <p style={ { color: '#999' } }>No price data available.</p>;
	}

	const chartData = data.map( ( d ) => ( {
		date: d.price_date,
		price: parseFloat( d.final_price ),
		store: d.store,
	} ) );

	return (
		<ResponsiveContainer width="100%" height={ 250 }>
			<LineChart data={ chartData }>
				<CartesianGrid strokeDasharray="3 3" />
				<XAxis dataKey="date" tick={ { fontSize: 11 } } />
				<YAxis
					tick={ { fontSize: 11 } }
					tickFormatter={ ( v ) => `\u20ac${ v.toFixed( 2 ) }` }
				/>
				<Tooltip
					formatter={ ( v ) => [ `\u20ac${ v.toFixed( 2 ) }`, 'Price' ] }
				/>
				<Line
					type="monotone"
					dataKey="price"
					stroke="#0073aa"
					strokeWidth={ 2 }
					dot={ { r: 3 } }
				/>
			</LineChart>
		</ResponsiveContainer>
	);
}
