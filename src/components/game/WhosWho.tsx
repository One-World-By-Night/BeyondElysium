/**
 * "Who's Who": the NPC and player-character directory a plain player sees.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import HelpButton from '../shared/HelpButton';
import { groupProfiles } from '../../lib/profileGrouping';
import type { CharacterProfile } from '../../types/character';
import './WhosWho.css';

export interface WhosWhoProps {
	gameSlug: string;
}

function portraitUrl(
	gameSlug: string,
	profile: CharacterProfile
): string | null {
	if ( profile.portrait_attachment_id ) {
		return api
			.attachments( gameSlug )
			.downloadUrl( profile.portrait_attachment_id );
	}
	return profile.image_url;
}

function ProfileCard( {
	gameSlug,
	profile,
}: {
	gameSlug: string;
	profile: CharacterProfile;
} ) {
	const image = portraitUrl( gameSlug, profile );
	return (
		<div className="be-whos-who__card" key={ profile.id }>
			{ image && (
				<img className="be-whos-who__portrait" src={ image } alt="" />
			) }
			<h3>{ profile.name }</h3>
			{ profile.played_by && (
				<p className="be-whos-who__played-by">
					{ __( 'Played by', 'beyond-elysium' ) }{ ' ' }
					{ profile.played_by }
				</p>
			) }
			{ profile.public_description && (
				<div
					className="be-whos-who__description"
					dangerouslySetInnerHTML={ {
						__html: profile.public_description,
					} }
				/>
			) }
			{ ( profile.titles.length > 0 || profile.factions.length > 0 ) && (
				<dl className="be-whos-who__meta">
					{ profile.titles.length > 0 && (
						<div>
							<dt>{ __( 'Titles', 'beyond-elysium' ) }</dt>
							<dd>{ profile.titles.join( ', ' ) }</dd>
						</div>
					) }
					{ profile.factions.length > 0 && (
						<div>
							<dt>{ __( 'Factions', 'beyond-elysium' ) }</dt>
							<dd>{ profile.factions.join( ', ' ) }</dd>
						</div>
					) }
				</dl>
			) }
		</div>
	);
}

export function WhosWho( { gameSlug }: WhosWhoProps ) {
	const [ profiles, setProfiles ] = useState< CharacterProfile[] | null >(
		null
	);
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		setProfiles( null );
		setError( null );
		api.npcs( gameSlug )
			.profiles()
			.then( setProfiles )
			.catch( () =>
				setError( __( "Failed to load Who's Who.", 'beyond-elysium' ) )
			);
	}, [ gameSlug ] );

	const { characters, npcs } = groupProfiles( profiles ?? [] );

	return (
		<div className="be-whos-who">
			<div className="be-help-heading">
				<h2>{ __( "Who's Who", 'beyond-elysium' ) }</h2>
				<HelpButton helpKey="whos-who" />
			</div>

			{ error && (
				<div className="be-whos-who__error" role="alert">
					{ error }
				</div>
			) }

			{ ! error && profiles === null && (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) }

			{ ! error && profiles !== null && profiles.length === 0 && (
				<p className="be-whos-who__empty">
					{ __(
						'No one has a public profile in this chronicle yet.',
						'beyond-elysium'
					) }
				</p>
			) }

			{ ! error && characters.length > 0 && (
				<>
					<h3 className="be-whos-who__group-title">
						{ __( 'Characters', 'beyond-elysium' ) }
					</h3>
					<div className="be-whos-who__grid">
						{ characters.map( ( profile ) => (
							<ProfileCard
								gameSlug={ gameSlug }
								profile={ profile }
								key={ profile.id }
							/>
						) ) }
					</div>
				</>
			) }

			{ ! error && npcs.length > 0 && (
				<>
					<h3 className="be-whos-who__group-title">
						{ __( 'NPCs', 'beyond-elysium' ) }
					</h3>
					<div className="be-whos-who__grid">
						{ npcs.map( ( profile ) => (
							<ProfileCard
								gameSlug={ gameSlug }
								profile={ profile }
								key={ profile.id }
							/>
						) ) }
					</div>
				</>
			) }
		</div>
	);
}

export default WhosWho;
