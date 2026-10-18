/**
 * Bonnie settings — a @wordpress/components admin screen over the REST API.
 *
 * Replaces the classic Settings-API form with cards, toggles and a radio group,
 * saving to /bonnie/v1/settings. The free core exposes its own settings; add-ons
 * inject their fields (e.g. Pro's IP anonymisation and trash policy) through the
 * `bonnie.settings.*` JS filters (see docs/HOOKS.md).
 */

import apiFetch from '@wordpress/api-fetch';
import { createRoot, Fragment, render, useEffect, useState } from '@wordpress/element';
import {
	Card,
	CardBody,
	CardHeader,
	ToggleControl,
	RadioControl,
	TextControl,
	Button,
	Notice,
	__experimentalHeading as Heading,
} from '@wordpress/components';
import { applyFilters } from '@wordpress/hooks';
import domReady from '@wordpress/dom-ready';
import { __, _n, sprintf } from '@wordpress/i18n';

const boot = window.bonnieSettings || {};
const upgradeUrl = boot.upgradeUrl || '#';

apiFetch.use( apiFetch.createRootURLMiddleware( boot.restUrl ) );
apiFetch.use( apiFetch.createNonceMiddleware( boot.nonce ) );

// Capabilities the separate Bonnie Pro add-on offers — shown in the info card.
const PRO_FEATURES = [
	__( 'Bulk delete and CSV/JSON export (single, filtered view, or selection)', 'the-bonnie-situation' ),
	__( 'Automatic trash workflow with a recovery window and hard cap', 'the-bonnie-situation' ),
	__( 'Per-form retention and capture overrides', 'the-bonnie-situation' ),
	__( 'Address book of deduplicated contacts', 'the-bonnie-situation' ),
	__( 'IP anonymisation', 'the-bonnie-situation' ),
	__( 'Encryption at rest, with the key kept out of the database', 'the-bonnie-situation' ),
	__( 'WP-CLI purge and import commands', 'the-bonnie-situation' ),
];

function Section( { title, children } ) {
	return (
		<Card style={ { marginBottom: 16 } }>
			<CardHeader>
				<Heading level={ 3 }>{ title }</Heading>
			</CardHeader>
			<CardBody>{ children }</CardBody>
		</Card>
	);
}

/**
 * Import card: lists Flamingo / CFDB7 data found on the site and imports it in
 * batches through /bonnie/v1/import. Renders nothing when there is no source.
 */
function ImportCard( { retentionDays, policy } ) {
	const [ sources, setSources ] = useState( [] );
	const [ running, setRunning ] = useState( null );
	const [ notice, setNotice ] = useState( null );

	const load = () =>
		apiFetch( { path: '/bonnie/v1/import' } )
			.then( ( list ) => setSources( Array.isArray( list ) ? list : [] ) )
			.catch( () => setSources( [] ) );

	useEffect( () => {
		load();
	}, [] );

	const run = async ( source ) => {
		setRunning( source.id );
		setNotice( null );
		let imported = 0;
		let skipped = 0;
		try {
			for ( ;; ) {
				const batch = await apiFetch( {
					path: '/bonnie/v1/import',
					method: 'POST',
					data: { source: source.id, limit: 50 },
				} );
				imported += batch.imported;
				skipped += batch.skipped;
				setSources( ( prev ) =>
					prev.map( ( s ) => ( s.id === source.id ? { ...s, remaining: batch.remaining } : s ) )
				);
				if ( batch.done || batch.imported + batch.skipped === 0 ) {
					break;
				}
			}
			setNotice( {
				status: 'success',
				text: sprintf(
					/* translators: 1: number imported, 2: source plugin name, 3: number skipped */
					__( 'Imported %1$d entries from %2$s. Skipped %3$d (spam, or already past your retention window).', 'the-bonnie-situation' ),
					imported,
					source.label,
					skipped
				),
			} );
		} catch ( err ) {
			setNotice( {
				status: 'error',
				text: err && err.message ? err.message : __( 'The import stopped. Run it again to continue where it left off.', 'the-bonnie-situation' ),
			} );
		} finally {
			setRunning( null );
			load();
		}
	};

	if ( ! sources.length ) {
		return null;
	}

	return (
		<div style={ { marginTop: 24 } }>
			<Section title={ __( 'Import', 'the-bonnie-situation' ) }>
				<p style={ { marginTop: 0 } }>
					{ __( 'Bring existing Contact Form 7 entries into Bonnie. They are stored under your current privacy settings, so IP addresses, user agents and referrers are dropped unless you have turned them on.', 'the-bonnie-situation' ) }
				</p>
				{ 'none' !== policy ? (
					<p>
						{ sprintf(
							/* translators: %d: retention window in days */
							__( 'Entries older than your %d-day retention window are skipped: they would be deleted on the next daily cleanup anyway.', 'the-bonnie-situation' ),
							Number( retentionDays ) || 30
						) }
					</p>
				) : null }
				{ notice ? (
					<div style={ { marginBottom: 12 } }>
						<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
							{ notice.text }
						</Notice>
					</div>
				) : null }
				{ sources.map( ( source ) => (
					<div
						key={ source.id }
						style={ { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 16, padding: '12px 0', borderTop: '1px solid #e0e0e0' } }
					>
						<div>
							<strong>{ source.label }</strong>
							<div style={ { color: '#757575' } }>
								{ source.remaining > 0
									? sprintf(
										/* translators: 1: entries left to import, 2: total entries */
										_n( '%1$d of %2$d entry left to import', '%1$d of %2$d entries left to import', source.total, 'the-bonnie-situation' ),
										source.remaining,
										source.total
									)
									: __( 'Everything has been imported.', 'the-bonnie-situation' ) }
							</div>
						</div>
						<Button
							variant="secondary"
							isBusy={ running === source.id }
							disabled={ null !== running || source.remaining < 1 }
							onClick={ () => run( source ) }
						>
							{ running === source.id
								? __( 'Importing…', 'the-bonnie-situation' )
								: __( 'Import', 'the-bonnie-situation' ) }
						</Button>
					</div>
				) ) }
				<p style={ { marginBottom: 0, color: '#757575' } }>
					{ __( 'Nothing is changed or removed in the source plugin. Once you have checked the import, delete the old entries there — otherwise those copies keep living in your database with no expiry.', 'the-bonnie-situation' ) }
				</p>
			</Section>
		</div>
	);
}

function SettingsApp() {
	const [ settings, setSettings ] = useState( boot.settings || {} );
	// Last-saved values: the importer applies these, not unsaved edits.
	const [ saved, setSaved ] = useState( boot.settings || {} );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const set = ( key, value ) => setSettings( ( prev ) => ( { ...prev, [ key ]: value } ) );
	const on = ( key ) => Boolean( Number( settings[ key ] ) );
	const str = ( key ) => ( settings[ key ] === undefined || settings[ key ] === null ? '' : String( settings[ key ] ) );
	const policy = settings.retention_policy || 'none';

	const save = () => {
		setSaving( true );
		setNotice( null );
		apiFetch( { path: '/bonnie/v1/settings', method: 'POST', data: settings } )
			.then( ( result ) => {
				setSettings( result );
				setSaved( result );
				setNotice( { status: 'success', text: __( 'Settings saved.', 'the-bonnie-situation' ) } );
			} )
			.catch( ( err ) => {
				setNotice( {
					status: 'error',
					text: err && err.message ? err.message : __( 'Could not save settings.', 'the-bonnie-situation' ),
				} );
			} )
			.finally( () => setSaving( false ) );
	};

	const toggle = ( key, label, help, extra = {} ) => (
		<div key={ key } style={ { marginBottom: 12 } }>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ label }
				help={ help }
				checked={ on( key ) }
				onChange={ ( value ) => set( key, value ) }
				{ ...extra }
			/>
		</div>
	);

	const number = ( key, label, help ) => (
		<div key={ key } style={ { marginBottom: 12, maxWidth: 220 } }>
			<TextControl
				__nextHasNoMarginBottom
				type="number"
				min="1"
				step="1"
				label={ label }
				help={ help }
				value={ str( key ) }
				onChange={ ( value ) => set( key, value ) }
			/>
		</div>
	);

	// Extension seam shared with the settings filters below.
	const api = { settings, set, on, str, toggle, number, policy };

	/**
	 * Extra controls injected into the Privacy card (e.g. Pro's IP anonymisation).
	 *
	 * @param {Array}  controls Base (empty) control list.
	 * @param {Object} api      Settings helpers { settings, set, on, str, toggle, number, policy }.
	 */
	const privacyExtra = applyFilters( 'bonnie.settings.privacyControls', [], api );

	/**
	 * Retention policy options. Free offers "retain" and "delete"; add-ons add
	 * their own (e.g. Pro's automatic trash).
	 *
	 * @param {Array} options Base policy options.
	 */
	const policyOptions = applyFilters( 'bonnie.settings.retentionPolicyOptions', [
		{ label: __( 'Retain entries indefinitely', 'the-bonnie-situation' ), value: 'none' },
		{ label: __( 'Delete entries permanently after the retention window', 'the-bonnie-situation' ), value: 'delete' },
	] );

	/**
	 * Extra controls injected into the Retention card (e.g. Pro's hard-cap field).
	 *
	 * @param {Array}  controls Base (empty) control list.
	 * @param {Object} api      Settings helpers.
	 */
	const retentionExtra = applyFilters( 'bonnie.settings.retentionControls', [], api );

	/**
	 * Whether to show the "Bonnie Pro" info card. The Pro add-on filters this off
	 * once it is active.
	 *
	 * @param {boolean} show Whether to show the card.
	 */
	const showUpsell = applyFilters( 'bonnie.settings.showUpsell', true );

	/**
	 * Extra cards rendered below the Save button. For add-on sections that are
	 * not part of the settings form (they save nothing to /bonnie/v1/settings).
	 *
	 * @param {Array}  cards Elements to render.
	 * @param {Object} api   `{ Section }` — the card wrapper the core sections use.
	 */
	const extraCards = applyFilters( 'bonnie.settings.cards', [], { Section } );

	return (
		<div style={ { maxWidth: 820 } }>
			{ notice ? (
				<div style={ { marginBottom: 16 } }>
					<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
						{ notice.text }
					</Notice>
				</div>
			) : null }

			<Section title={ __( 'Privacy', 'the-bonnie-situation' ) }>
				{ toggle( 'store_ip', __( 'Store IP addresses', 'the-bonnie-situation' ), __( 'Off is privacy-first and recommended. When off, the IP is never written.', 'the-bonnie-situation' ) ) }
				{ privacyExtra }
				{ toggle( 'store_user_agent', __( 'Store the user agent', 'the-bonnie-situation' ) ) }
				{ toggle( 'store_referer', __( 'Store the referring page URL', 'the-bonnie-situation' ) ) }
			</Section>

			<Section title={ __( 'Retention', 'the-bonnie-situation' ) }>
				<div style={ { marginBottom: 16 } }>
					<RadioControl
						label={ __( 'Retention policy', 'the-bonnie-situation' ) }
						selected={ policy }
						options={ policyOptions }
						onChange={ ( value ) => set( 'retention_policy', value ) }
					/>
				</div>
				{ policy !== 'none'
					? number(
						'retention_days',
						'delete' === policy
							? __( 'Days to retain before deleting', 'the-bonnie-situation' )
							: __( 'Days to retain before acting', 'the-bonnie-situation' ),
						__( 'Minimum 1.', 'the-bonnie-situation' )
					)
					: null }
				{ retentionExtra }
			</Section>

			<Section title={ __( 'Capture', 'the-bonnie-situation' ) }>
				{ toggle( 'store_on_mail_failed', __( 'Store submissions even when the email failed to send', 'the-bonnie-situation' ) ) }
				{ toggle( 'capture_spam', __( 'Store submissions flagged as spam', 'the-bonnie-situation' ) ) }
				{ toggle( 'capture_validation', __( 'Store submissions that failed validation', 'the-bonnie-situation' ) ) }
			</Section>

			<Section title={ __( 'Data', 'the-bonnie-situation' ) }>
				{ toggle( 'delete_data_on_uninstall', __( 'Delete all stored data and tables when the plugin is uninstalled', 'the-bonnie-situation' ), __( 'Off by default so accidental removal never destroys records.', 'the-bonnie-situation' ) ) }
			</Section>

			<Button variant="primary" isBusy={ saving } disabled={ saving } onClick={ save }>
				{ __( 'Save changes', 'the-bonnie-situation' ) }
			</Button>

			<ImportCard retentionDays={ saved.retention_days } policy={ saved.retention_policy || 'none' } />

			{ extraCards.length ? (
				<div style={ { marginTop: 24 } }>
					{ extraCards.map( ( card, i ) => (
						<Fragment key={ i }>{ card }</Fragment>
					) ) }
				</div>
			) : null }

			{ showUpsell ? (
				<Card style={ { marginTop: 24 } }>
					<CardHeader>
						<Heading level={ 3 }>{ __( 'Bonnie Pro', 'the-bonnie-situation' ) }</Heading>
					</CardHeader>
					<CardBody>
						<p style={ { marginTop: 0 } }>
							{ __( 'Bonnie treats stored data as a liability. The separate Bonnie Pro add-on adds tools to manage it at scale:', 'the-bonnie-situation' ) }
						</p>
						<ul style={ { margin: '0 0 16px', paddingLeft: 20, listStyle: 'disc' } }>
							{ PRO_FEATURES.map( ( feature ) => (
								<li key={ feature } style={ { marginBottom: 4 } }>{ feature }</li>
							) ) }
						</ul>
						<Button variant="secondary" href={ upgradeUrl } target="_blank" rel="noreferrer">
							{ __( 'Learn more about Bonnie Pro ↗', 'the-bonnie-situation' ) }
						</Button>
					</CardBody>
				</Card>
			) : null }
		</div>
	);
}

domReady( () => {
	const root = document.getElementById( 'bonnie-settings-app' );
	if ( ! root ) {
		return;
	}
	if ( createRoot ) {
		createRoot( root ).render( <SettingsApp /> );
	} else {
		render( <SettingsApp />, root );
	}
} );
