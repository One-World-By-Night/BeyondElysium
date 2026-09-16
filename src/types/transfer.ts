/**
 * Type definitions for chronicle-to-chronicle character transfer
 * (GX-8/9): the transfer row itself, the derived travelling/
 * visiting badge merged onto a character, the outbound initiate
 * action's response, and the receiving side's review of an offer.
 */
import type { ImportedEntity, ImportPreview } from './import';

/**
 * One leg of a character's journey - an outbound row on the home
 * chronicle, an inbound row on the host. Mirrors `be_character_transfers`
 * directly.
 */
export interface Transfer {
	id: number;
	character_uuid: string;
	character_id: number | null;
	/** The character's name when the transfer was made - an offer has no local character yet. */
	character_name: string | null;
	direction: 'outbound' | 'inbound';
	state: string;
	home_slug: string;
	home_site: string;
	home_chronicle: string;
	host_slug: string | null;
	host_site: string | null;
	host_chronicle: string | null;
	attestation_id: number | null;
	snapshot_id: number | null;
	payload_hash: string;
	initiated_by: number;
	initiated_at: string;
	acknowledged_at: string | null;
	returned_at: string | null;
	notes: string | null;
}

/**
 * The derived badge `Characters_Controller` merges onto a character
 * row - present only while a transfer touching that character, from
 * either side, is still open.
 */
export interface TravellingStatus {
	character_uuid: string;
	direction: 'outbound' | 'inbound';
	state: string;
	/** Null on an outbound row until a host has confirmed - no chronicle name to show yet. */
	chronicle: string | null;
	since: string;
}

/**
 * Response from initiating an outbound transfer. `xml` is present
 * whenever the offline carrier is still usable - always, so the
 * download always works even when a host was also POSTed to
 * directly.
 */
export interface InitiateTransferResponse {
	transfer: Transfer;
	xml: string;
	warnings: string[];
	/** What the host answered: `pending_review` while its Storytellers decide; `message` when it turned the offer away. */
	host?: {
		accepted?: boolean;
		pending_review?: boolean;
		message?: string;
		host_chronicle?: string;
	} | null;
}

/**
 * A waiting inbound offer, shown the way the Import page shows a parsed file.
 */
export interface TransferReview {
	transfer: Transfer;
	preview: ImportPreview;
}

/**
 * Response from accepting an offer: the row, now `visiting`, and the character it created or updated.
 */
export interface TransferAcceptResult {
	transfer: Transfer;
	character: ImportedEntity;
}
