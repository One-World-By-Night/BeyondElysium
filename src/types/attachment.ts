/**
 * Type definitions for file uploads on a plot, item, or location.
 */

/**
 * The kind of entity an attachment may belong to.
 */
export type AttachmentEntityType = 'plot' | 'item' | 'location';

/**
 * A single uploaded file's public metadata, as returned by the attachments endpoints.
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
