import { readFileSync } from 'fs';
import { join } from 'path';

/**
 * `GameNights.tsx` once defaulted its spotlight-days field to 14 while `Spotlight::DEFAULT_SPOTLIGHT_DAYS` (the value
 * actually enforced until a save happened) was 42.
 */
describe( 'spotlight days default stays in sync with the server', () => {
	it( 'GameNights.tsx defaults match Spotlight::DEFAULT_SPOTLIGHT_DAYS', () => {
		const phpSource = readFileSync(
			join(
				__dirname,
				'../../beyond-elysium/includes/Services/Spotlight.php'
			),
			'utf8'
		);
		const match = phpSource.match(
			/const DEFAULT_SPOTLIGHT_DAYS\s*=\s*(\d+)/
		);
		expect( match ).not.toBeNull();
		const serverDefault = ( match as RegExpMatchArray )[ 1 ];

		const tsxSource = readFileSync(
			join( __dirname, '../components/game/GameNights.tsx' ),
			'utf8'
		);

		const initialState = tsxSource.match(
			/const \[ spotlightDays, setSpotlightDays \] = useState\(\s*(\d+)\s*\)/
		);
		const loadedFallback = tsxSource.match(
			/setSpotlightDays\(\s*settings\?\.sessions\?\.spotlight_days\s*\?\?\s*(\d+)\s*\)/
		);

		expect( initialState ).not.toBeNull();
		expect( loadedFallback ).not.toBeNull();
		expect( ( initialState as RegExpMatchArray )[ 1 ] ).toBe(
			serverDefault
		);
		expect( ( loadedFallback as RegExpMatchArray )[ 1 ] ).toBe(
			serverDefault
		);
	} );
} );
