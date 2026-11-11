/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * A public holiday, from Holiday::jsonSerialize(): GET /api/holidays/applying
 * and the answers of POST and PUT /api/holidays. A recurring holiday is served
 * once per year of the asked range, each time with that year's date and the
 * same id.
 */
export interface HolidayRecord {
	id: number
	/** A country ('DE'), a subdivision ('DE-BY') or '*' for everywhere. */
	region: string
	/** 'YYYY-MM-DD'. */
	date: string
	label: string
	/** On the same day and month every year, from the year of its date on. */
	recurring: boolean
	/** Uid, null for holidays added from occ or before authorship was kept. */
	createdBy: string | null
	/** Uid of who last edited it, null when never edited. */
	updatedBy: string | null
}

/** A holiday in GET /api/holidays, with the names of who added and edited it. */
export interface Holiday extends HolidayRecord {
	createdByName: string | null
	updatedByName: string | null
}

/** Query of GET /api/holidays. */
export interface HolidayListParams {
	/** 2000..2100. */
	year: number
	/** Only this region code; '' or left out for every region. */
	region?: string | null
}

/** Query of GET /api/holidays/applying: the days a region code gets (its own, its country's and the everywhere ones). */
export interface ApplyingHolidaysParams {
	/** '' for only the everywhere days. */
	code: string
	/** 'YYYY-MM-DD'; at most three years up to `to`. */
	from: string
	to: string
}

/** GET /api/holidays/regions: the region codes that have holidays, '*' included. */
export type HolidayRegions = string[]

/** Body of POST /api/holidays and PUT /api/holidays/{id}. An update replaces every field. */
export interface HolidayData {
	date: string
	region: string
	label: string
	/** False when left out, also on update. */
	recurring?: boolean
}

/** A holiday as the history and the import describe it. */
export interface HolidayDescription {
	label: string
	date: string
	region: string
	recurring: boolean
}

interface HolidayChangeBase {
	/** Null for a change made from occ. */
	actorUid: string | null
	actorName: string | null
	/** ISO 8601 timestamp. */
	at: string
}

export interface HolidayAddedChange extends HolidayChangeBase {
	action: 'added' | 'deleted'
	detail: HolidayDescription
}

export interface HolidayChangedChange extends HolidayChangeBase {
	action: 'changed'
	detail: {
		before: HolidayDescription
		after: HolidayDescription
	}
}

export interface HolidayImportedChange extends HolidayChangeBase {
	action: 'imported'
	detail: {
		count: number
	}
}

/** Somebody switched who may edit the list: everyone, or HR only. */
export interface HolidayEditorsChange extends HolidayChangeBase {
	action: 'everyone_edits' | 'hr_edits'
	/** Nothing to tell: PHP encodes the empty detail as `[]`. */
	detail: []
}

/** One entry of GET /api/holidays/history, newest first (the last 200). */
export type HolidayChange = HolidayAddedChange | HolidayChangedChange | HolidayImportedChange | HolidayEditorsChange

export type HolidayChangeAction = HolidayChange['action']
