/**
 * The small icons over the bottom bar's labels.
 */

const PATHS: Record< string, string > = {
	home: 'M3 11.5 12 4l9 7.5V21h-6v-6H9v6H3z',
	person: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-8 9c0-4 3.6-6.5 8-6.5s8 2.5 8 6.5Z',
	people: 'M8.5 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm8 0a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM1.5 20c0-3.5 3.2-5.5 7-5.5s7 2 7 5.5Zm15-5.4c3.3.2 6 2 6 5.4h-5.2c0-2-.3-3.8-.8-5.4Z',
	scroll: 'M6 3h10l4 4v14H6Zm3 6v2h8V9Zm0 4v2h8v-2Zm0 4v2h5v-2Z',
	check: 'M9.5 16.6 4.9 12l-1.4 1.4 6 6 11-11-1.4-1.4Z',
	more: 'M5 10.5a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Zm7 0a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Zm7 0a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Z',
	dot: 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z',
};

/**
 * The icon each section shows on the bar, by its key; a section with none of its own shows a dot.
 */
const BY_SECTION: Record< string, string > = {
	dashboard: 'home',
	sheet: 'person',
	characters: 'people',
	plots: 'scroll',
	'approval-queue': 'check',
	more: 'more',
};

export function AppIcon( { name }: { name: string } ) {
	const path = PATHS[ BY_SECTION[ name ] ?? 'dot' ];
	return (
		<svg
			className="be-app-icon"
			viewBox="0 0 24 24"
			width="22"
			height="22"
			aria-hidden="true"
			focusable="false"
		>
			<path d={ path } fill="currentColor" />
		</svg>
	);
}

export default AppIcon;
