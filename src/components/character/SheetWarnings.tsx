/**
 * What a Storyteller should look at on a sheet: a path held under one tradition spelled two ways.
 */
import { __, sprintf } from '@wordpress/i18n';
import type { SheetWarning } from '../../types/character';
import './SheetWarnings.css';

export interface SheetWarningsProps {
	warnings?: SheetWarning[];
}

/**
 * A notice listing each repeated holding; nothing when there are none.
 */
export default function SheetWarnings( { warnings }: SheetWarningsProps ) {
	if ( ! warnings || warnings.length === 0 ) {
		return null;
	}

	return (
		<div className="be-sheet-warnings" role="status">
			<p className="be-sheet-warnings__lead">
				{ __(
					'This sheet holds some entries twice. Open the editor and remove the extra one.',
					'beyond-elysium'
				) }
			</p>
			<ul className="be-sheet-warnings__list">
				{ warnings.map( ( warning ) => (
					<li
						key={ `${ warning.block_slug }:${ warning.name }:${ warning.traditions.join(
							'|'
						) }` }
					>
						{ sprintf(
							/* translators: 1: a path's name, 2: the tradition spelled both ways, 3: the level each copy holds */
							__(
								'%1$s is held as %2$s (levels %3$s).',
								'beyond-elysium'
							),
							warning.name,
							warning.traditions.join( ' and ' ),
							warning.levels
								.map( ( level ) => level ?? '-' )
								.join( ' and ' )
						) }
					</li>
				) ) }
			</ul>
		</div>
	);
}
