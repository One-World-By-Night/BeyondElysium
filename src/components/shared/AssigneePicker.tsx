/**
 * Who owns a plot or NPC: unassigned, or one of this chronicle's own hst/ast/narrator members.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import type { StaffMember } from '../../types/staffQueue';

export interface AssigneePickerProps {
	gameSlug: string;
	value: number | null;
	onChange: ( assignedTo: number | null ) => void;
	disabled?: boolean;
}

export function AssigneePicker( {
	gameSlug,
	value,
	onChange,
	disabled,
}: AssigneePickerProps ) {
	const [ staff, setStaff ] = useState< StaffMember[] >( [] );

	useEffect( () => {
		api.myQueue( gameSlug )
			.staff()
			.then( setStaff )
			.catch( () => setStaff( [] ) );
	}, [ gameSlug ] );

	return (
		<select
			className="be-assignee-picker"
			value={ value ?? '' }
			disabled={ disabled }
			onChange={ ( e ) =>
				onChange( e.target.value ? Number( e.target.value ) : null )
			}
		>
			<option value="">{ __( 'Unassigned', 'beyond-elysium' ) }</option>
			{ staff.map( ( member ) => (
				<option key={ member.id } value={ member.id }>
					{ member.name }
				</option>
			) ) }
		</select>
	);
}

export default AssigneePicker;
