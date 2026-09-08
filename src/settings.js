/**
 * Bonnie settings — a @wordpress/components admin screen over the REST API.
 *
 * Replaces the classic Settings-API form with cards, toggles and a radio group,
 * saving to /bonnie/v1/settings. The free core exposes its own settings; add-ons
 * inject their fields (e.g. Pro's IP anonymisation and trash policy) through the
 * `bonnie.settings.*` JS filters (see docs/HOOKS.md).
 */

import apiFetch from '@wordpress/api-fetch';
import { createRoot, render, useState } from '@wordpress/element';
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
import { __ } from '@wordpress/i18n';

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
	__( 'Encryption at rest', 'the-bonnie-situation' ),
	__( 'WP-CLI purge command', 'the-bonnie-situation' ),
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

function SettingsApp() {
	const [ settings, setSettings ] = useState( boot.settings || {} );
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
			.then( ( saved ) => {
				setSettings( saved );
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
