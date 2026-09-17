/**
 * Type definitions for file uploads on a plot, item, or location
 * (1.1.0 §2.6). Mirrors Attachment::public_shape() exactly - never
 * carries stored_name, the server-only handle used to locate the
 * file on disk.
 */

/**
 * The kind of entity an attachment may belong to. A narrower set
 * than EntityType (plot.ts) - a rote, boon, character, or tag can
 * never carry a file.
 */
export type AttachmentEntityType = 'plot' | 'item' | 'location';

/**
 * A single uploaded file's public metadata, as returned by the
 * attachments endpoints. The actual bytes are fetched separately
 * via downloadUrl(), never embedded here.
 */
export interface Attachment {
	id: number;
	entity_type: AttachmentEntityType;
	entity_id: number;
	original_name: string;
	mime: string;
	bytes: number;
	created_by: number;
	created_at: string;
}
