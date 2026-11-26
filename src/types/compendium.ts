/**
 * What the compendium routes return: a chronicle's catalog a creature type and a section at a time.
 */
import type { Prerequisite } from './index';

export interface CompendiumType {
	slug: string;
	name: string;
}

export interface CompendiumSection {
	block_slug: string;
	label: string;
	kind: 'items' | 'powers';
	count: number;
}

export interface CompendiumSections {
	slug: string;
	name: string;
	sections: CompendiumSection[];
}

export interface CompendiumItem {
	name: string;
	name_pt: string | null;
	group: string | null;
	subgroup: string | null;
	tier: string | null;
	cost: string | null;
	book: string | null;
	description: string | null;
	citation: string;
	prerequisites: Prerequisite[];
}

export interface CompendiumLevel {
	level: number | null;
	tier: string | null;
	power_name: string;
	power_name_pt: string | null;
	cost: string | null;
	description: string | null;
}

export interface CompendiumFamily {
	name: string;
	name_pt: string | null;
	group: string | null;
	book: string | null;
	description: string | null;
	citation: string;
	levels: CompendiumLevel[];
}

export type CompendiumEntry = CompendiumItem | CompendiumFamily;

export interface CompendiumEntries {
	block_slug: string;
	label: string;
	kind: 'items' | 'powers';
	entries: CompendiumEntry[];
}

export interface CompendiumHit {
	block_slug: string;
	label: string;
	name: string;
	name_pt: string | null;
	family: string | null;
}
