/**
 * Whether a sheet section has anything to show: a held trait or power, an identity field with a value, or a
 * resource pool.
 */
import type {
	BlockDefinition,
	IdentityFieldDefinition,
	ResourcePoolDefinition,
	SectionType,
} from '../types';
import { filledIdentityFields } from './identityValue';

export function sectionHasContent(
	sectionType: SectionType,
	definition: BlockDefinition,
	data: unknown
): boolean {
	switch ( sectionType ) {
		case 'trait_list':
		case 'tiered_power':
			return Array.isArray( data ) && data.length > 0;

		case 'resource_pool':
			return (
				( ( definition as ResourcePoolDefinition ).pools ?? [] )
					.length > 0
			);

		case 'identity_field': {
			return (
				filledIdentityFields(
					( definition as IdentityFieldDefinition ).fields ?? [],
					data as Record< string, unknown > | null | undefined
				).length > 0
			);
		}

		default:
			return true;
	}
}
