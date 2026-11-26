/**
 * Makes a held trait's row tappable when the page can open the trait panel, and leaves it exactly as it was when not.
 */
import type { ReactNode } from 'react';
import './TapTarget.css';

export type TraitTap = (
	blockSlug: string,
	name: string,
	powerName?: string
) => void;

export interface TapTargetProps {
	onTrait?: TraitTap;
	blockSlug: string;
	name: string;
	powerName?: string;
	children: ReactNode;
}

export function TapTarget( {
	onTrait,
	blockSlug,
	name,
	powerName,
	children,
}: TapTargetProps ) {
	if ( ! onTrait ) {
		return <>{ children }</>;
	}
	return (
		<button
			type="button"
			className="be-tap-target"
			onClick={ () => onTrait( blockSlug, name, powerName ) }
		>
			{ children }
		</button>
	);
}

export default TapTarget;
